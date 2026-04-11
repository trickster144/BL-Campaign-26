import { RowDataPacket } from 'mysql2/promise';
import { query, execute } from './databaseConfig.js';
import { ESPIONAGE, TICKS_PER_DAY } from './gameConstants.js';

// ── Types ────────────────────────────────────────────────────────────────────
interface SpyRow extends RowDataPacket {
  id: number;
  user_id: number;
  location_id: number;
  training_level: number;
  is_active: boolean;
  last_report: string | null;
  risk_level: number;
}

// Ensure extra spy columns
async function ensureSpyColumns(): Promise<void> {
  try {
    await execute('ALTER TABLE spies ADD COLUMN IF NOT EXISTS name VARCHAR(100) NULL');
    await execute('ALTER TABLE spies ADD COLUMN IF NOT EXISTS team ENUM("blue","red") NULL');
    await execute('ALTER TABLE spies ADD COLUMN IF NOT EXISTS target_location_id INT NULL');
    await execute('ALTER TABLE spies ADD COLUMN IF NOT EXISTS status ENUM("idle","training","deployed","captured","dead") DEFAULT "idle"');
    await execute('ALTER TABLE spies ADD COLUMN IF NOT EXISTS training_start_tick BIGINT NULL');
    await execute('ALTER TABLE spies ADD COLUMN IF NOT EXISTS training_duration_ticks BIGINT NULL');
  } catch { /* may already exist */ }
}

// ── Spy management ───────────────────────────────────────────────────────────

export async function recruitSpy(team: 'blue' | 'red', name: string): Promise<number> {
  await ensureSpyColumns();

  // Use a dummy user_id and location_id; real implementation ties to a user
  const result = await execute(
    `INSERT INTO spies (user_id, location_id, training_level, is_active, risk_level, name, team, status)
     VALUES (1, 1, 0, TRUE, 0, ?, ?, 'idle')`,
    [name, team],
  );
  return result.insertId;
}

export async function trainSpy(spyId: number, ticks: number): Promise<{ success: boolean; message?: string }> {
  await ensureSpyColumns();

  const spies = await query<SpyRow[]>('SELECT * FROM spies WHERE id = ?', [spyId]);
  if (spies.length === 0) return { success: false, message: 'Spy not found' };

  const tickRows = await query<(RowDataPacket & { current_tick: number })[]>(
    'SELECT current_tick FROM game_state WHERE id = 1',
  );
  const currentTick = Number(tickRows[0]?.current_tick ?? 0);

  await execute(
    `UPDATE spies SET status = 'training', training_start_tick = ?, training_duration_ticks = ?
     WHERE id = ?`,
    [currentTick, ticks, spyId],
  );

  return { success: true };
}

export async function deploySpy(spyId: number, targetLocationId: number): Promise<{ success: boolean; message?: string }> {
  await ensureSpyColumns();

  const spies = await query<SpyRow[]>('SELECT * FROM spies WHERE id = ?', [spyId]);
  if (spies.length === 0) return { success: false, message: 'Spy not found' };

  await execute(
    `UPDATE spies SET status = 'deployed', target_location_id = ?, is_active = TRUE
     WHERE id = ?`,
    [targetLocationId, spyId],
  );

  return { success: true };
}

// ── Espionage rolls (every 1440 ticks) ──────────────────────────────────────

export async function processEspionageRolls(tick: number): Promise<void> {
  if (tick % ESPIONAGE.roll_interval_ticks !== 0) return;
  await ensureSpyColumns();

  // Process training completions first
  const trainees = await query<(SpyRow & { training_start_tick: number; training_duration_ticks: number })[]>(
    `SELECT * FROM spies WHERE status = 'training'`,
  );

  for (const spy of trainees) {
    const elapsed = tick - Number(spy.training_start_tick ?? 0);
    if (elapsed >= Number(spy.training_duration_ticks ?? 0)) {
      // Training complete: increase level
      const newLevel = Math.min(100, Number(spy.training_level) + 5);
      await execute(
        `UPDATE spies SET training_level = ?, status = 'idle', training_start_tick = NULL, training_duration_ticks = NULL
         WHERE id = ?`,
        [newLevel, spy.id],
      );
    }
  }

  // Roll for deployed spies
  const deployed = await query<SpyRow[]>(
    `SELECT * FROM spies WHERE status = 'deployed' AND is_active = TRUE`,
  );

  for (const spy of deployed) {
    // Generate intel report
    await generateIntelReport(spy.id);

    // Detection roll
    const detectionThreshold =
      ESPIONAGE.base_detection_chance -
      Number(spy.training_level) * ESPIONAGE.training_reduction_per_level;

    const roll = Math.random();
    if (roll < detectionThreshold) {
      // Detected!
      const outcomeRoll = Math.random();
      const trainingBonus = Number(spy.training_level) * 0.005; // better-trained spies escape more

      if (outcomeRoll < ESPIONAGE.outcome_detected.escape + trainingBonus) {
        // Escaped — spy returns to idle, no longer deployed at target
        await execute(
          `UPDATE spies SET status = 'idle', target_location_id = NULL, risk_level = LEAST(risk_level + 10, 100)
           WHERE id = ?`,
          [spy.id],
        );
      } else if (outcomeRoll < ESPIONAGE.outcome_detected.escape + ESPIONAGE.outcome_detected.captured + trainingBonus) {
        // Captured
        await captureSpy(spy.id);
      } else {
        // Executed
        await execute(
          `UPDATE spies SET status = 'dead', is_active = FALSE WHERE id = ?`,
          [spy.id],
        );
      }
    }

    // Update last report time
    await execute('UPDATE spies SET last_report = NOW() WHERE id = ?', [spy.id]);
  }
}

// ── Intel report generation ──────────────────────────────────────────────────

export async function generateIntelReport(spyId: number): Promise<number | null> {
  await ensureSpyColumns();

  const spies = await query<SpyRow[]>('SELECT * FROM spies WHERE id = ?', [spyId]);
  if (spies.length === 0) return null;
  const spy = spies[0];

  const targetLocId = (spy as unknown as { target_location_id: number | null }).target_location_id;
  if (!targetLocId) return null;

  // Accuracy based on training level (0–100)
  const accuracy = Math.min(95, 30 + Number(spy.training_level) * 0.7);

  // Gather intel about target location
  const resources = await query<(RowDataPacket & { name: string; quantity: number })[]>(
    `SELECT r.name, lr.quantity FROM location_resources lr
     JOIN resources r ON r.id = lr.resource_id
     WHERE lr.location_id = ?`,
    [targetLocId],
  );

  const buildings = await query<(RowDataPacket & { type: string; level: number })[]>(
    'SELECT type, level FROM buildings WHERE location_id = ?',
    [targetLocId],
  );

  const armies = await query<(RowDataPacket & { name: string; strength: number })[]>(
    'SELECT name, strength FROM armies WHERE location_id = ?',
    [targetLocId],
  );

  // Apply accuracy: fuzz the numbers
  const fuzzFactor = 1 - (accuracy / 100);
  const fuzzedResources = resources.map(r => ({
    name: r.name,
    quantity: Math.round(Number(r.quantity) * (1 + (Math.random() - 0.5) * fuzzFactor * 2)),
  }));
  const fuzzedArmies = armies.map(a => ({
    name: a.name,
    strength: Math.round(Number(a.strength) * (1 + (Math.random() - 0.5) * fuzzFactor * 2)),
  }));

  const reportData = {
    location_id: targetLocId,
    resources: fuzzedResources,
    buildings: buildings.map(b => ({ type: b.type, level: b.level })),
    armies: fuzzedArmies,
    timestamp: new Date().toISOString(),
  };

  const result = await execute(
    `INSERT INTO espionage_reports (spy_id, report_type, data, accuracy)
     VALUES (?, 'reconnaissance', ?, ?)`,
    [spyId, JSON.stringify(reportData), accuracy],
  );

  return result.insertId;
}

// ── Capture / Exchange ───────────────────────────────────────────────────────

export async function captureSpy(spyId: number): Promise<void> {
  await ensureSpyColumns();
  await execute(
    `UPDATE spies SET status = 'captured', is_active = FALSE WHERE id = ?`,
    [spyId],
  );
}

export async function exchangePrisoners(spyIdA: number, spyIdB: number): Promise<{ success: boolean; message?: string }> {
  await ensureSpyColumns();

  const spyA = await query<SpyRow[]>('SELECT * FROM spies WHERE id = ?', [spyIdA]);
  const spyB = await query<SpyRow[]>('SELECT * FROM spies WHERE id = ?', [spyIdB]);

  if (spyA.length === 0 || spyB.length === 0) return { success: false, message: 'Spy not found' };

  const statusA = (spyA[0] as unknown as { status: string }).status;
  const statusB = (spyB[0] as unknown as { status: string }).status;

  if (statusA !== 'captured' || statusB !== 'captured') {
    return { success: false, message: 'Both spies must be captured for exchange' };
  }

  // Release both
  await execute(
    `UPDATE spies SET status = 'idle', is_active = TRUE, target_location_id = NULL WHERE id = ?`,
    [spyIdA],
  );
  await execute(
    `UPDATE spies SET status = 'idle', is_active = TRUE, target_location_id = NULL WHERE id = ?`,
    [spyIdB],
  );

  return { success: true };
}
