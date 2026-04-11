import { RowDataPacket } from 'mysql2/promise';
import { query, execute, getConnection } from './databaseConfig.js';
import { BUILDING_TYPES, PRODUCTION_RECIPES, BuildingTypeDef } from './gameConstants.js';
import { removeResource, addResource } from './resourceService.js';

// ── Types ────────────────────────────────────────────────────────────────────
interface BuildingRow extends RowDataPacket {
  id: number;
  location_id: number;
  type: string;
  level: number;
  health: number;
  is_operational: boolean;
  construction_started: string | null;
  construction_completed: string | null;
  workers_assigned: number;
  team: string;
}

// Ensure extra building columns
async function ensureBuildingColumns(): Promise<void> {
  try {
    await execute('ALTER TABLE buildings ADD COLUMN IF NOT EXISTS workers_assigned INT DEFAULT 0');
    await execute('ALTER TABLE buildings ADD COLUMN IF NOT EXISTS team ENUM("blue","red") NULL');
    await execute('ALTER TABLE buildings ADD COLUMN IF NOT EXISTS construction_ticks_remaining INT DEFAULT 0');
    await execute('ALTER TABLE buildings ADD COLUMN IF NOT EXISTS repair_in_progress BOOLEAN DEFAULT FALSE');
  } catch { /* may already exist */ }
}

// ── Getters ──────────────────────────────────────────────────────────────────

export function getBuildingTypes(): Record<string, BuildingTypeDef> {
  return BUILDING_TYPES;
}

export async function getBuildings(locationId: number): Promise<BuildingRow[]> {
  await ensureBuildingColumns();
  return query<BuildingRow[]>(
    'SELECT * FROM buildings WHERE location_id = ?',
    [locationId],
  );
}

// ── Construction ─────────────────────────────────────────────────────────────

// Construction costs (simplified: base cost per building category)
const CONSTRUCTION_COSTS: Record<string, { resource: string; qty: number }[]> = {
  production:      [{ resource: 'steel', qty: 20 }, { resource: 'bricks', qty: 50 }, { resource: 'cement', qty: 10 }],
  infrastructure:  [{ resource: 'steel', qty: 10 }, { resource: 'bricks', qty: 30 }, { resource: 'cement', qty: 5 }],
  civic:           [{ resource: 'bricks', qty: 40 }, { resource: 'cement', qty: 8 }],
  military:        [{ resource: 'steel', qty: 30 }, { resource: 'bricks', qty: 60 }, { resource: 'cement', qty: 15 }],
  agriculture:     [{ resource: 'wood', qty: 10 }, { resource: 'bricks', qty: 20 }],
};

const CONSTRUCTION_DURATION_TICKS: Record<string, number> = {
  production:     2_880,  // 2 days
  infrastructure: 1_440,  // 1 day
  civic:          1_440,
  military:       4_320,  // 3 days
  agriculture:    720,    // 12 hours
};

export async function constructBuilding(
  locationId: number,
  buildingTypeId: string,
  team: 'blue' | 'red',
): Promise<{ success: boolean; buildingId?: number; message?: string }> {
  await ensureBuildingColumns();

  const bType = BUILDING_TYPES[buildingTypeId];
  if (!bType) return { success: false, message: `Unknown building type: ${buildingTypeId}` };

  // Check and consume construction resources
  const costs = CONSTRUCTION_COSTS[bType.category] ?? CONSTRUCTION_COSTS.infrastructure;
  for (const cost of costs) {
    const result = await removeResource(locationId, cost.resource, cost.qty);
    if (!result.success) {
      return { success: false, message: `Insufficient ${cost.resource}: ${result.message}` };
    }
  }

  const durationTicks = CONSTRUCTION_DURATION_TICKS[bType.category] ?? 1_440;

  const result = await execute(
    `INSERT INTO buildings (location_id, type, level, health, is_operational, construction_started, team, construction_ticks_remaining)
     VALUES (?, ?, 1, 100, FALSE, NOW(), ?, ?)`,
    [locationId, buildingTypeId, team, durationTicks],
  );

  return { success: true, buildingId: result.insertId };
}

// ── Construction processing ──────────────────────────────────────────────────

export async function processConstruction(tick: number): Promise<void> {
  await ensureBuildingColumns();

  // Decrease remaining ticks for all under-construction buildings
  await execute(
    `UPDATE buildings SET construction_ticks_remaining = construction_ticks_remaining - 1
     WHERE is_operational = FALSE AND construction_completed IS NULL AND construction_ticks_remaining > 0`,
  );

  // Complete buildings that have finished
  await execute(
    `UPDATE buildings SET
       is_operational = TRUE,
       construction_completed = NOW(),
       construction_ticks_remaining = 0
     WHERE is_operational = FALSE AND construction_completed IS NULL AND construction_ticks_remaining <= 0
       AND construction_started IS NOT NULL`,
  );
}

// ── Upgrades ─────────────────────────────────────────────────────────────────

export async function upgradeBuilding(buildingId: number): Promise<{ success: boolean; newLevel?: number; message?: string }> {
  await ensureBuildingColumns();

  const buildings = await query<BuildingRow[]>('SELECT * FROM buildings WHERE id = ?', [buildingId]);
  if (buildings.length === 0) return { success: false, message: 'Building not found' };
  const bld = buildings[0];

  if (!bld.is_operational) return { success: false, message: 'Building not operational' };
  if (bld.level >= 5) return { success: false, message: 'Maximum level reached' };

  const bType = BUILDING_TYPES[bld.type];
  if (!bType) return { success: false, message: 'Unknown building type' };

  // Upgrade costs scale with level
  const costs = CONSTRUCTION_COSTS[bType.category] ?? CONSTRUCTION_COSTS.infrastructure;
  for (const cost of costs) {
    const scaledQty = cost.qty * (bld.level + 1);
    const result = await removeResource(bld.location_id, cost.resource, scaledQty);
    if (!result.success) {
      return { success: false, message: `Insufficient ${cost.resource} for upgrade` };
    }
  }

  const newLevel = bld.level + 1;
  await execute('UPDATE buildings SET level = ? WHERE id = ?', [newLevel, buildingId]);

  return { success: true, newLevel };
}

// ── Repair ───────────────────────────────────────────────────────────────────

export async function repairBuilding(buildingId: number): Promise<{ success: boolean; message?: string }> {
  await ensureBuildingColumns();

  const buildings = await query<BuildingRow[]>('SELECT * FROM buildings WHERE id = ?', [buildingId]);
  if (buildings.length === 0) return { success: false, message: 'Building not found' };
  const bld = buildings[0];

  if (Number(bld.health) >= 100) return { success: false, message: 'Building already at full health' };

  // Check for workshop at location
  const workshops = await query<(RowDataPacket & { id: number })[]>(
    `SELECT id FROM buildings WHERE location_id = ? AND type = 'workshop' AND is_operational = TRUE`,
    [bld.location_id],
  );
  if (workshops.length === 0) {
    return { success: false, message: 'No operational workshop at location' };
  }

  // Repair costs: small amount of steel per 10% repair
  const repairPercent = 100 - Number(bld.health);
  const steelNeeded = Math.ceil(repairPercent / 10);
  const result = await removeResource(bld.location_id, 'steel', steelNeeded);
  if (!result.success) return { success: false, message: `Insufficient steel for repair` };

  await execute('UPDATE buildings SET health = 100, repair_in_progress = FALSE WHERE id = ?', [buildingId]);

  return { success: true };
}

// ── Demolish ─────────────────────────────────────────────────────────────────

export async function demolishBuilding(buildingId: number): Promise<{ success: boolean; message?: string }> {
  const buildings = await query<BuildingRow[]>('SELECT * FROM buildings WHERE id = ?', [buildingId]);
  if (buildings.length === 0) return { success: false, message: 'Building not found' };

  // Return some resources (30% of construction cost)
  const bld = buildings[0];
  const bType = BUILDING_TYPES[bld.type];
  if (bType) {
    const costs = CONSTRUCTION_COSTS[bType.category] ?? [];
    for (const cost of costs) {
      await addResource(bld.location_id, cost.resource, Math.floor(cost.qty * 0.3));
    }
  }

  // Generate construction waste
  await addResource(bld.location_id, 'construction_waste', 5);

  await execute('DELETE FROM buildings WHERE id = ?', [buildingId]);

  return { success: true };
}

// ── Damage ───────────────────────────────────────────────────────────────────

export async function damageBuilding(buildingId: number, amount: number): Promise<void> {
  await execute(
    'UPDATE buildings SET health = GREATEST(health - ?, 0) WHERE id = ?',
    [amount, buildingId],
  );

  // If health reaches 0, building becomes non-operational
  await execute(
    'UPDATE buildings SET is_operational = FALSE WHERE id = ? AND health <= 0',
    [buildingId],
  );
}

// ── Worker assignment ────────────────────────────────────────────────────────

export async function assignWorkers(buildingId: number, count: number): Promise<{ success: boolean; message?: string }> {
  await ensureBuildingColumns();

  const buildings = await query<BuildingRow[]>('SELECT * FROM buildings WHERE id = ?', [buildingId]);
  if (buildings.length === 0) return { success: false, message: 'Building not found' };
  const bld = buildings[0];

  const bType = BUILDING_TYPES[bld.type];
  if (!bType) return { success: false, message: 'Unknown building type' };

  if (count > bType.maxWorkers) {
    return { success: false, message: `Maximum workers for ${bType.name} is ${bType.maxWorkers}` };
  }

  await execute('UPDATE buildings SET workers_assigned = ? WHERE id = ?', [count, buildingId]);

  return { success: true };
}
