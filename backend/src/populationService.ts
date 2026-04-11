import { RowDataPacket } from 'mysql2/promise';
import { query, execute, getConnection } from './databaseConfig.js';
import {
  WORKER_CONSUMPTION,
  HAPPINESS_MODIFIERS,
  STARVATION,
  TICKS_PER_DAY,
} from './gameConstants.js';
import { getResourceQuantity, removeResource, addResource } from './resourceService.js';

// ── Types ────────────────────────────────────────────────────────────────────
export interface PopulationGroup extends RowDataPacket {
  id: number;
  location_id: number;
  age_group: 'babies' | 'children' | 'adults' | 'elderly';
  count: number;
  education_level: 'none' | 'primary' | 'advanced';
}

interface LocationRow extends RowDataPacket {
  id: number;
  name: string;
  population: number;
  happiness: number;
}

// In-memory starvation tracker: locationId → consecutive ticks without food
const starvationCounters: Map<number, number> = new Map();

// ── Getters ──────────────────────────────────────────────────────────────────

export async function getPopulation(locationId: number): Promise<PopulationGroup[]> {
  return query<PopulationGroup[]>(
    'SELECT * FROM population_groups WHERE location_id = ?',
    [locationId],
  );
}

async function getAdultCount(locationId: number): Promise<number> {
  const rows = await query<(RowDataPacket & { total: number })[]>(
    `SELECT COALESCE(SUM(\`count\`), 0) AS total
     FROM population_groups
     WHERE location_id = ? AND age_group = 'adults'`,
    [locationId],
  );
  return Number(rows[0]?.total ?? 0);
}

async function getTotalPopulation(locationId: number): Promise<number> {
  const rows = await query<(RowDataPacket & { total: number })[]>(
    'SELECT COALESCE(SUM(`count`), 0) AS total FROM population_groups WHERE location_id = ?',
    [locationId],
  );
  return Number(rows[0]?.total ?? 0);
}

// ── Aging ────────────────────────────────────────────────────────────────────
// Babies → children after ~4320 ticks (3 days, representing ~5 years)
// Children → adults after ~14400 ticks (10 days, representing ~13 years)
// Adults → elderly after ~86400 ticks (60 days, representing ~40+ years)

const AGING_THRESHOLDS = {
  babies_to_children: 4_320,   // ~3 days
  children_to_adults: 14_400,  // ~10 days
  adults_to_elderly: 86_400,   // ~60 days
  elderly_death_chance: 0.0005, // per tick chance of natural death
};

export async function processAging(tick: number): Promise<void> {
  // Process aging based on tick intervals (simplified: batch convert a fraction each tick)
  if (tick % TICKS_PER_DAY !== 0) return; // age once per day

  const conn = await getConnection();
  try {
    await conn.beginTransaction();

    // Babies → children (0.023% per day ≈ 1/4320 daily conversion rate)
    const conversionRateBaby = 1 / (AGING_THRESHOLDS.babies_to_children / TICKS_PER_DAY);
    await conn.execute(
      `UPDATE population_groups pg
       JOIN (
         SELECT location_id, FLOOR(\`count\` * ?) AS convert_count
         FROM population_groups WHERE age_group = 'babies' AND \`count\` > 0
       ) src ON pg.location_id = src.location_id AND pg.age_group = 'babies'
       SET pg.\`count\` = pg.\`count\` - src.convert_count`,
      [conversionRateBaby],
    );
    // Add to children (upsert)
    const [babies] = await conn.execute<(RowDataPacket & { location_id: number; convert_count: number })[]>(
      `SELECT location_id, FLOOR(\`count\` * ?) AS convert_count
       FROM population_groups WHERE age_group = 'babies' AND \`count\` > 0`,
      [conversionRateBaby],
    );
    for (const b of babies) {
      if (b.convert_count <= 0) continue;
      await conn.execute(
        `UPDATE population_groups SET \`count\` = \`count\` + ?
         WHERE location_id = ? AND age_group = 'children'`,
        [b.convert_count, b.location_id],
      );
    }

    // Children → adults
    const conversionRateChild = 1 / (AGING_THRESHOLDS.children_to_adults / TICKS_PER_DAY);
    const [children] = await conn.execute<(RowDataPacket & { location_id: number; convert_count: number })[]>(
      `SELECT location_id, FLOOR(\`count\` * ?) AS convert_count
       FROM population_groups WHERE age_group = 'children' AND \`count\` > 0`,
      [conversionRateChild],
    );
    for (const c of children) {
      if (c.convert_count <= 0) continue;
      await conn.execute(
        `UPDATE population_groups SET \`count\` = \`count\` - ? WHERE location_id = ? AND age_group = 'children'`,
        [c.convert_count, c.location_id],
      );
      await conn.execute(
        `UPDATE population_groups SET \`count\` = \`count\` + ? WHERE location_id = ? AND age_group = 'adults'`,
        [c.convert_count, c.location_id],
      );
    }

    // Adults → elderly
    const conversionRateAdult = 1 / (AGING_THRESHOLDS.adults_to_elderly / TICKS_PER_DAY);
    const [adults] = await conn.execute<(RowDataPacket & { location_id: number; convert_count: number })[]>(
      `SELECT location_id, FLOOR(\`count\` * ?) AS convert_count
       FROM population_groups WHERE age_group = 'adults' AND \`count\` > 0`,
      [conversionRateAdult],
    );
    for (const a of adults) {
      if (a.convert_count <= 0) continue;
      await conn.execute(
        `UPDATE population_groups SET \`count\` = \`count\` - ? WHERE location_id = ? AND age_group = 'adults'`,
        [a.convert_count, a.location_id],
      );
      await conn.execute(
        `UPDATE population_groups SET \`count\` = \`count\` + ? WHERE location_id = ? AND age_group = 'elderly'`,
        [a.convert_count, a.location_id],
      );
    }

    await conn.commit();
  } catch (err) {
    await conn.rollback();
    console.error('processAging error:', err);
  } finally {
    conn.release();
  }
}

// ── Births ───────────────────────────────────────────────────────────────────

export async function processBirths(tick: number): Promise<void> {
  if (tick % TICKS_PER_DAY !== 0) return;

  // Birth rate: ~0.1% of adult population per day
  const BIRTH_RATE = 0.001;

  const locations = await query<(RowDataPacket & { location_id: number; adult_count: number })[]>(
    `SELECT location_id, \`count\` AS adult_count
     FROM population_groups WHERE age_group = 'adults' AND \`count\` > 0`,
  );

  for (const loc of locations) {
    const newBabies = Math.floor(loc.adult_count * BIRTH_RATE);
    if (newBabies <= 0) continue;
    await execute(
      `UPDATE population_groups SET \`count\` = \`count\` + ?
       WHERE location_id = ? AND age_group = 'babies'`,
      [newBabies, loc.location_id],
    );
    // Update total population on location
    await execute(
      'UPDATE locations SET population = population + ? WHERE id = ?',
      [newBabies, loc.location_id],
    );
  }
}

// ── Deaths ───────────────────────────────────────────────────────────────────

export async function processDeaths(tick: number): Promise<void> {
  if (tick % TICKS_PER_DAY !== 0) return;

  // Natural death for elderly
  const elderly = await query<(RowDataPacket & { id: number; location_id: number; count: number })[]>(
    `SELECT id, location_id, \`count\` FROM population_groups WHERE age_group = 'elderly' AND \`count\` > 0`,
  );

  for (const e of elderly) {
    const deaths = Math.floor(e.count * AGING_THRESHOLDS.elderly_death_chance * TICKS_PER_DAY);
    if (deaths <= 0) continue;
    const actual = Math.min(deaths, e.count);
    await execute(
      'UPDATE population_groups SET `count` = `count` - ? WHERE id = ?',
      [actual, e.id],
    );
    await execute(
      'UPDATE locations SET population = population - ? WHERE id = ?',
      [actual, e.location_id],
    );
  }

  // Starvation deaths
  const locations = await query<(RowDataPacket & { id: number })[]>('SELECT id FROM locations');
  for (const loc of locations) {
    const counter = starvationCounters.get(loc.id) ?? 0;
    if (counter >= STARVATION.death_after_ticks) {
      // Kill some adults and elderly
      const adults = await getAdultCount(loc.id);
      const deathCount = Math.max(1, Math.floor(adults * 0.01)); // 1% per day once threshold hit
      await execute(
        `UPDATE population_groups SET \`count\` = GREATEST(\`count\` - ?, 0)
         WHERE location_id = ? AND age_group = 'adults'`,
        [deathCount, loc.id],
      );
      await execute(
        'UPDATE locations SET population = GREATEST(population - ?, 0) WHERE id = ?',
        [deathCount, loc.id],
      );
    }
  }
}

// ── Starvation tracking ──────────────────────────────────────────────────────

export async function processStarvation(locationId: number): Promise<void> {
  const foodQty = await getResourceQuantity(locationId, 'food');
  const adults = await getAdultCount(locationId);
  const neededPerTick = (adults * WORKER_CONSUMPTION.food_kg_per_day) / TICKS_PER_DAY;

  if (foodQty < neededPerTick) {
    const current = starvationCounters.get(locationId) ?? 0;
    starvationCounters.set(locationId, current + 1);
  } else {
    starvationCounters.set(locationId, 0);
  }
}

// ── Consumption ──────────────────────────────────────────────────────────────

export async function processConsumption(locationId: number, _tick: number): Promise<void> {
  const adults = await getAdultCount(locationId);
  if (adults <= 0) return;

  // Per-tick consumption (daily amounts / ticks_per_day)
  const foodPerTick = (adults * WORKER_CONSUMPTION.food_kg_per_day) / TICKS_PER_DAY;
  const meatPerTick = (adults * (WORKER_CONSUMPTION.meat_g_per_day / 1000)) / TICKS_PER_DAY;
  const clothesPerTick = (adults * (WORKER_CONSUMPTION.clothing_g_per_day / 1000)) / TICKS_PER_DAY;
  const alcoholPerTick = (adults * (WORKER_CONSUMPTION.alcohol_ml_per_day / 1000)) / TICKS_PER_DAY;

  await removeResource(locationId, 'food', foodPerTick);
  await removeResource(locationId, 'meat', meatPerTick);
  await removeResource(locationId, 'clothes', clothesPerTick);
  await removeResource(locationId, 'alcohol', alcoholPerTick);

  // Track starvation
  await processStarvation(locationId);
}

// ── Happiness ────────────────────────────────────────────────────────────────

export async function calculateHappiness(locationId: number): Promise<number> {
  let happiness = 50; // base

  const meatQty = await getResourceQuantity(locationId, 'meat');
  const clothesQty = await getResourceQuantity(locationId, 'clothes');
  const alcoholQty = await getResourceQuantity(locationId, 'alcohol');
  const foodQty = await getResourceQuantity(locationId, 'food');

  const adults = await getAdultCount(locationId);
  if (adults <= 0) return happiness;

  // Satisfaction checks
  const meatNeeded = (adults * WORKER_CONSUMPTION.meat_g_per_day) / 1000;
  const clothesNeeded = (adults * WORKER_CONSUMPTION.clothing_g_per_day) / 1000;
  const alcoholNeeded = (adults * WORKER_CONSUMPTION.alcohol_ml_per_day) / 1000;

  if (meatQty < meatNeeded) happiness += HAPPINESS_MODIFIERS.no_meat * 100;
  if (clothesQty < clothesNeeded) happiness += HAPPINESS_MODIFIERS.no_clothing * 100;
  if (alcoholQty < alcoholNeeded) happiness += HAPPINESS_MODIFIERS.no_alcohol * 100;

  // Starvation penalty
  const starvDays = (starvationCounters.get(locationId) ?? 0) / TICKS_PER_DAY;
  happiness -= starvDays * STARVATION.productivity_loss_per_day * 100;

  happiness = Math.max(0, Math.min(100, happiness));

  await execute('UPDATE locations SET happiness = ? WHERE id = ?', [happiness, locationId]);

  return happiness;
}

// ── Productivity ─────────────────────────────────────────────────────────────

export async function calculateProductivity(locationId: number): Promise<number> {
  const happiness = await calculateHappiness(locationId);
  const starvDays = (starvationCounters.get(locationId) ?? 0) / TICKS_PER_DAY;
  const starvPenalty = Math.min(1, starvDays * STARVATION.productivity_loss_per_day);
  const productivity = Math.max(0, (happiness / 100) * (1 - starvPenalty));
  return productivity;
}
