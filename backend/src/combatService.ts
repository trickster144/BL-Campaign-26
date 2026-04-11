import { RowDataPacket } from 'mysql2/promise';
import { query, execute, getConnection } from './databaseConfig.js';
import { UNIT_TYPES, COMBAT, HEALTH } from './gameConstants.js';
import { getCombatModifier, getCurrentWeather } from './weatherService.js';

// ── Types ────────────────────────────────────────────────────────────────────
interface ArmyRow extends RowDataPacket {
  id: number;
  name: string;
  team: 'blue' | 'red';
  location_id: number | null;
  general_id: number | null;
  strength: number;
  morale: number;
  supplies: string | null;
  is_moving: boolean;
  destination_hex_q: number | null;
  destination_hex_r: number | null;
}

interface ArmyUnitRow extends RowDataPacket {
  id: number;
  army_id: number;
  unit_type: string;
  count: number;
  health: number;
  experience: number;
}

interface BattleRow extends RowDataPacket {
  id: number;
  location_id: number;
  attacker_army_id: number;
  defender_army_id: number;
  start_time: string;
  end_time: string | null;
  winner: string | null;
  casualties_attacker: number;
  casualties_defender: number;
  battle_log: string | null;
}

// Ensure military movement columns exist
async function ensureMilitaryColumns(): Promise<void> {
  try {
    await execute(`ALTER TABLE armies ADD COLUMN IF NOT EXISTS destination_hex_q INT NULL`);
    await execute(`ALTER TABLE armies ADD COLUMN IF NOT EXISTS destination_hex_r INT NULL`);
    await execute(`ALTER TABLE armies ADD COLUMN IF NOT EXISTS move_start_tick BIGINT NULL`);
    await execute(`ALTER TABLE armies ADD COLUMN IF NOT EXISTS move_arrival_tick BIGINT NULL`);
  } catch { /* columns may already exist */ }
}

// Ensure wounded table
async function ensureWoundedTable(): Promise<void> {
  await execute(`
    CREATE TABLE IF NOT EXISTS wounded (
      id INT PRIMARY KEY AUTO_INCREMENT,
      army_id INT NOT NULL,
      unit_type VARCHAR(50) NOT NULL,
      count INT DEFAULT 0,
      location_id INT NOT NULL,
      admitted_tick BIGINT NOT NULL,
      healed BOOLEAN DEFAULT FALSE,
      FOREIGN KEY (army_id) REFERENCES armies(id) ON DELETE CASCADE,
      FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
  `);
}

// ── Army CRUD ────────────────────────────────────────────────────────────────

export async function createArmy(
  team: 'blue' | 'red',
  name: string,
  generalId: number | null,
  locationId: number,
): Promise<number> {
  await ensureMilitaryColumns();
  const result = await execute(
    `INSERT INTO armies (name, team, general_id, location_id, strength, morale, supplies)
     VALUES (?, ?, ?, ?, 0, 50, '{}')`,
    [name, team, generalId, locationId],
  );
  return result.insertId;
}

export async function addUnitsToArmy(
  armyId: number,
  unitType: string,
  count: number,
): Promise<{ success: boolean; message?: string }> {
  if (!UNIT_TYPES[unitType]) return { success: false, message: `Unknown unit type: ${unitType}` };

  const existing = await query<ArmyUnitRow[]>(
    'SELECT * FROM army_units WHERE army_id = ? AND unit_type = ?',
    [armyId, unitType],
  );

  if (existing.length > 0) {
    await execute(
      'UPDATE army_units SET `count` = `count` + ? WHERE army_id = ? AND unit_type = ?',
      [count, armyId, unitType],
    );
  } else {
    await execute(
      'INSERT INTO army_units (army_id, unit_type, `count`, health, experience) VALUES (?, ?, ?, 100, 0)',
      [armyId, unitType, count],
    );
  }

  // Update army strength
  await execute(
    `UPDATE armies SET strength = (SELECT COALESCE(SUM(\`count\`), 0) FROM army_units WHERE army_id = ?)
     WHERE id = ?`,
    [armyId, armyId],
  );

  return { success: true };
}

// ── Army movement ────────────────────────────────────────────────────────────

export async function moveArmy(
  armyId: number,
  destinationHexQ: number,
  destinationHexR: number,
): Promise<{ success: boolean; message?: string }> {
  await ensureMilitaryColumns();

  const armies = await query<ArmyRow[]>('SELECT * FROM armies WHERE id = ?', [armyId]);
  if (armies.length === 0) return { success: false, message: 'Army not found' };
  if (armies[0].is_moving) return { success: false, message: 'Army already moving' };

  const tickRows = await query<(RowDataPacket & { current_tick: number })[]>(
    'SELECT current_tick FROM game_state WHERE id = 1',
  );
  const currentTick = Number(tickRows[0]?.current_tick ?? 0);

  // Get army's slowest unit speed
  const units = await query<ArmyUnitRow[]>('SELECT * FROM army_units WHERE army_id = ?', [armyId]);
  let minSpeed = Infinity;
  for (const u of units) {
    const unitDef = UNIT_TYPES[u.unit_type];
    if (unitDef && unitDef.speed < minSpeed) minSpeed = unitDef.speed;
  }
  if (minSpeed === Infinity) minSpeed = 4;

  // Calculate hex distance (axial coordinates)
  const currentLoc = await query<(RowDataPacket & { hex_x: number; hex_y: number })[]>(
    'SELECT hex_x, hex_y FROM locations WHERE id = ?',
    [armies[0].location_id],
  );
  let hexDistance = 5; // default
  if (currentLoc.length > 0) {
    const dx = destinationHexQ - currentLoc[0].hex_x;
    const dy = destinationHexR - currentLoc[0].hex_y;
    hexDistance = Math.max(Math.abs(dx), Math.abs(dy), Math.abs(dx + dy));
  }

  const travelTicks = Math.ceil((hexDistance / minSpeed) * 60);

  await execute(
    `UPDATE armies SET
       is_moving = TRUE,
       destination_hex_q = ?,
       destination_hex_r = ?,
       move_start_tick = ?,
       move_arrival_tick = ?
     WHERE id = ?`,
    [destinationHexQ, destinationHexR, currentTick, currentTick + travelTicks, armyId],
  );

  return { success: true };
}

// ── Combat processing (OGame-style) ─────────────────────────────────────────

export async function processCombat(tick: number): Promise<void> {
  if (tick % COMBAT.round_interval_ticks !== 0) return;
  await ensureMilitaryColumns();

  // Check for arrived armies
  const arrivedArmies = await query<ArmyRow[]>(
    `SELECT * FROM armies WHERE is_moving = TRUE AND move_arrival_tick <= ?`,
    [tick],
  );

  for (const army of arrivedArmies) {
    // Find destination location
    const destLocs = await query<(RowDataPacket & { id: number })[]>(
      'SELECT id FROM locations WHERE hex_x = ? AND hex_y = ?',
      [army.destination_hex_q, army.destination_hex_r],
    );
    const destLocId = destLocs.length > 0 ? destLocs[0].id : army.location_id;

    await execute(
      `UPDATE armies SET
         is_moving = FALSE,
         location_id = ?,
         destination_hex_q = NULL,
         destination_hex_r = NULL,
         move_start_tick = NULL,
         move_arrival_tick = NULL
       WHERE id = ?`,
      [destLocId, army.id],
    );
  }

  // Find opposing armies at same location
  const locations = await query<(RowDataPacket & { location_id: number })[]>(
    `SELECT DISTINCT location_id FROM armies WHERE location_id IS NOT NULL AND is_moving = FALSE`,
  );

  for (const loc of locations) {
    const armiesAtLoc = await query<ArmyRow[]>(
      'SELECT * FROM armies WHERE location_id = ? AND is_moving = FALSE AND strength > 0',
      [loc.location_id],
    );

    const blueArmies = armiesAtLoc.filter(a => a.team === 'blue');
    const redArmies = armiesAtLoc.filter(a => a.team === 'red');

    if (blueArmies.length > 0 && redArmies.length > 0) {
      // Battle! Pair them up
      for (const attacker of blueArmies) {
        for (const defender of redArmies) {
          if (attacker.strength <= 0 || defender.strength <= 0) continue;

          // Check for existing active battle
          const existingBattle = await query<BattleRow[]>(
            `SELECT * FROM battles
             WHERE ((attacker_army_id = ? AND defender_army_id = ?) OR (attacker_army_id = ? AND defender_army_id = ?))
               AND end_time IS NULL`,
            [attacker.id, defender.id, defender.id, attacker.id],
          );

          let battleId: number;
          if (existingBattle.length > 0) {
            battleId = existingBattle[0].id;
          } else {
            const result = await execute(
              `INSERT INTO battles (location_id, attacker_army_id, defender_army_id, battle_log)
               VALUES (?, ?, ?, '[]')`,
              [loc.location_id, attacker.id, defender.id],
            );
            battleId = result.insertId;
          }

          await resolveBattleRound(battleId);
        }
      }
    }
  }
}

export async function resolveBattleRound(battleId: number): Promise<void> {
  const battles = await query<BattleRow[]>('SELECT * FROM battles WHERE id = ?', [battleId]);
  if (battles.length === 0) return;
  const battle = battles[0];
  if (battle.end_time) return; // already ended

  const attackerUnits = await query<ArmyUnitRow[]>(
    'SELECT * FROM army_units WHERE army_id = ? AND `count` > 0',
    [battle.attacker_army_id],
  );
  const defenderUnits = await query<ArmyUnitRow[]>(
    'SELECT * FROM army_units WHERE army_id = ? AND `count` > 0',
    [battle.defender_army_id],
  );

  if (attackerUnits.length === 0 || defenderUnits.length === 0) {
    // Battle over
    const winner = attackerUnits.length > 0 ? 'attacker' : (defenderUnits.length > 0 ? 'defender' : 'draw');
    await execute(
      'UPDATE battles SET end_time = NOW(), winner = ? WHERE id = ?',
      [winner, battleId],
    );
    return;
  }

  const weatherMod = getCombatModifier(getCurrentWeather());

  // Calculate total attack for attacker (OGame formula)
  let totalAttackerDamage = 0;
  for (const unit of attackerUnits) {
    const def = UNIT_TYPES[unit.unit_type];
    if (!def) continue;
    const expMod = 1 + (Number(unit.experience) / 100);
    const moraleMod = 1; // simplified
    totalAttackerDamage += unit.count * def.attack * expMod * moraleMod * weatherMod;
  }

  // Calculate total attack for defender
  let totalDefenderDamage = 0;
  for (const unit of defenderUnits) {
    const def = UNIT_TYPES[unit.unit_type];
    if (!def) continue;
    const expMod = 1 + (Number(unit.experience) / 100);
    totalDefenderDamage += unit.count * def.attack * expMod * weatherMod;
  }

  // Distribute attacker damage across defender's units
  let remainingAttackerDmg = totalAttackerDamage;
  let defenderCasualties = 0;
  const roundLog: { attacker_damage: number; defender_damage: number; casualties_attacker: number; casualties_defender: number } = {
    attacker_damage: totalAttackerDamage,
    defender_damage: totalDefenderDamage,
    casualties_attacker: 0,
    casualties_defender: 0,
  };

  for (const unit of defenderUnits) {
    if (remainingAttackerDmg <= 0) break;
    const def = UNIT_TYPES[unit.unit_type];
    if (!def) continue;

    const unitTotalHP = unit.count * def.health * (Number(unit.health) / 100);
    const effectiveDefense = unit.count * def.defense;
    const netDamage = Math.max(0, remainingAttackerDmg - effectiveDefense * 0.5);

    const killed = Math.min(unit.count, Math.floor(netDamage / def.health));
    const wounded = Math.min(unit.count - killed, Math.floor((netDamage % def.health) / (def.health * 0.5)));

    defenderCasualties += killed;
    remainingAttackerDmg -= unitTotalHP;

    await execute(
      'UPDATE army_units SET `count` = GREATEST(`count` - ?, 0) WHERE id = ?',
      [killed + wounded, unit.id],
    );

    // Wounded go to hospital
    if (wounded > 0) {
      await ensureWoundedTable();
      const tickRows = await query<(RowDataPacket & { current_tick: number })[]>(
        'SELECT current_tick FROM game_state WHERE id = 1',
      );
      await execute(
        `INSERT INTO wounded (army_id, unit_type, count, location_id, admitted_tick)
         VALUES (?, ?, ?, ?, ?)`,
        [unit.army_id, unit.unit_type, wounded, battle.location_id, tickRows[0]?.current_tick ?? 0],
      );
    }
  }

  // Distribute defender damage across attacker's units
  let remainingDefenderDmg = totalDefenderDamage;
  let attackerCasualties = 0;

  for (const unit of attackerUnits) {
    if (remainingDefenderDmg <= 0) break;
    const def = UNIT_TYPES[unit.unit_type];
    if (!def) continue;

    const effectiveDefense = unit.count * def.defense;
    const netDamage = Math.max(0, remainingDefenderDmg - effectiveDefense * 0.5);
    const killed = Math.min(unit.count, Math.floor(netDamage / def.health));

    attackerCasualties += killed;
    remainingDefenderDmg -= unit.count * def.health * (Number(unit.health) / 100);

    await execute(
      'UPDATE army_units SET `count` = GREATEST(`count` - ?, 0) WHERE id = ?',
      [killed, unit.id],
    );
  }

  roundLog.casualties_attacker = attackerCasualties;
  roundLog.casualties_defender = defenderCasualties;

  // Update battle casualties
  await execute(
    `UPDATE battles SET
       casualties_attacker = casualties_attacker + ?,
       casualties_defender = casualties_defender + ?,
       battle_log = JSON_ARRAY_APPEND(COALESCE(battle_log, '[]'), '$', CAST(? AS JSON))
     WHERE id = ?`,
    [attackerCasualties, defenderCasualties, JSON.stringify(roundLog), battleId],
  );

  // Surviving units gain experience
  for (const unit of [...attackerUnits, ...defenderUnits]) {
    await execute(
      'UPDATE army_units SET experience = LEAST(experience + 0.5, 100) WHERE id = ? AND `count` > 0',
      [unit.id],
    );
  }

  // Update army strengths
  await execute(
    `UPDATE armies SET strength = (SELECT COALESCE(SUM(\`count\`), 0) FROM army_units WHERE army_id = armies.id)
     WHERE id IN (?, ?)`,
    [battle.attacker_army_id, battle.defender_army_id],
  );

  // Check if battle should end (one side eliminated)
  const atkStrength = await query<(RowDataPacket & { strength: number })[]>(
    'SELECT strength FROM armies WHERE id = ?',
    [battle.attacker_army_id],
  );
  const defStrength = await query<(RowDataPacket & { strength: number })[]>(
    'SELECT strength FROM armies WHERE id = ?',
    [battle.defender_army_id],
  );

  if ((atkStrength[0]?.strength ?? 0) <= 0 || (defStrength[0]?.strength ?? 0) <= 0) {
    const winner = (atkStrength[0]?.strength ?? 0) > 0 ? 'attacker' : ((defStrength[0]?.strength ?? 0) > 0 ? 'defender' : 'draw');
    await execute('UPDATE battles SET end_time = NOW(), winner = ? WHERE id = ?', [winner, battleId]);
  }
}

// ── Wounded processing ───────────────────────────────────────────────────────

export async function processWounded(tick: number): Promise<void> {
  await ensureWoundedTable();

  // Move wounded to hospitals (already done at combat time)
  // Check treatment deadline: if not at hospital within 24hrs, they die
  const untreated = await query<(RowDataPacket & { id: number; location_id: number; admitted_tick: number; count: number })[]>(
    `SELECT w.* FROM wounded w
     LEFT JOIN buildings b ON b.location_id = w.location_id AND b.type = 'hospital' AND b.is_operational = TRUE
     WHERE w.healed = FALSE AND b.id IS NULL`,
  );

  for (const w of untreated) {
    if (tick - Number(w.admitted_tick) >= HEALTH.treatment_deadline_ticks) {
      // No hospital available: wounded die
      await execute('UPDATE wounded SET healed = TRUE WHERE id = ?', [w.id]);
    }
  }
}

export async function processHospitals(tick: number): Promise<void> {
  await ensureWoundedTable();

  // Heal patients at hospitals
  const patients = await query<(RowDataPacket & {
    id: number; army_id: number; unit_type: string; count: number;
    location_id: number; admitted_tick: number;
  })[]>(
    `SELECT w.* FROM wounded w
     JOIN buildings b ON b.location_id = w.location_id AND b.type = 'hospital' AND b.is_operational = TRUE
     WHERE w.healed = FALSE`,
  );

  for (const p of patients) {
    if (tick - Number(p.admitted_tick) >= HEALTH.healing_duration_ticks) {
      // Healed: return to army
      await execute(
        'UPDATE army_units SET `count` = `count` + ? WHERE army_id = ? AND unit_type = ?',
        [p.count, p.army_id, p.unit_type],
      );
      await execute(
        `UPDATE armies SET strength = (SELECT COALESCE(SUM(\`count\`), 0) FROM army_units WHERE army_id = ?)
         WHERE id = ?`,
        [p.army_id, p.army_id],
      );
      await execute('UPDATE wounded SET healed = TRUE WHERE id = ?', [p.id]);
    }
  }
}
