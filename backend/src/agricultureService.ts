import { RowDataPacket } from 'mysql2/promise';
import { query, execute } from './databaseConfig.js';
import { getCropModifier, getCurrentWeather, WeatherState } from './weatherService.js';
import { addResource, removeResource } from './resourceService.js';

// ── Types ────────────────────────────────────────────────────────────────────
interface FarmRow extends RowDataPacket {
  id: number;
  location_id: number;
  size: number;
  crop_type: string;
  growth_stage: number;
  fertilizer_level: number;
  harvest_ready: boolean;
  planted_date: string | null;
  harvest_date: string | null;
  team: string;
}

// Ensure team column on farms
async function ensureFarmColumns(): Promise<void> {
  try {
    await execute('ALTER TABLE farms ADD COLUMN IF NOT EXISTS team ENUM("blue","red") NULL');
    await execute('ALTER TABLE farms ADD COLUMN IF NOT EXISTS spoilage_ticks INT DEFAULT 0');
  } catch { /* may already exist */ }
}

// ── Field management ─────────────────────────────────────────────────────────

export async function createField(
  locationId: number,
  team: 'blue' | 'red',
  sizeHectares: number,
  cropType: string,
): Promise<number> {
  await ensureFarmColumns();
  const result = await execute(
    `INSERT INTO farms (location_id, size, crop_type, growth_stage, fertilizer_level, team)
     VALUES (?, ?, ?, 0, 0, ?)`,
    [locationId, sizeHectares, cropType, team],
  );
  return result.insertId;
}

export async function plantField(fieldId: number): Promise<{ success: boolean; message?: string }> {
  const farms = await query<FarmRow[]>('SELECT * FROM farms WHERE id = ?', [fieldId]);
  if (farms.length === 0) return { success: false, message: 'Field not found' };
  const farm = farms[0];

  if (farm.growth_stage > 0) return { success: false, message: 'Field already planted' };

  // Check for tractor at location (a vehicle of type truck can serve as tractor)
  const tractors = await query<(RowDataPacket & { id: number })[]>(
    `SELECT v.id FROM vehicles v
     JOIN vehicle_types vt ON vt.id = v.vehicle_type_id
     WHERE v.location_id = ? AND v.is_moving = FALSE AND v.health > 0
     LIMIT 1`,
    [farm.location_id],
  );

  if (tractors.length === 0) {
    return { success: false, message: 'No tractor/vehicle available at location' };
  }

  await execute(
    `UPDATE farms SET growth_stage = 0.01, planted_date = NOW() WHERE id = ?`,
    [fieldId],
  );

  return { success: true };
}

export async function fertilizeField(fieldId: number, amount: number): Promise<{ success: boolean; message?: string }> {
  const farms = await query<FarmRow[]>('SELECT * FROM farms WHERE id = ?', [fieldId]);
  if (farms.length === 0) return { success: false, message: 'Field not found' };
  const farm = farms[0];

  // Consume liquid_fertilizer from location
  const result = await removeResource(farm.location_id, 'liquid_fertilizer', amount);
  if (!result.success) return { success: false, message: result.message };

  const newLevel = Math.min(100, Number(farm.fertilizer_level) + amount * 10);
  await execute('UPDATE farms SET fertilizer_level = ? WHERE id = ?', [newLevel, fieldId]);

  return { success: true };
}

// ── Growth processing ────────────────────────────────────────────────────────

export async function processGrowth(tick: number): Promise<void> {
  const weather = getCurrentWeather();
  const cropMod = getCropModifier(weather);

  const farms = await query<FarmRow[]>(
    'SELECT * FROM farms WHERE growth_stage > 0 AND growth_stage < 100 AND harvest_ready = FALSE',
  );

  for (const farm of farms) {
    // Base growth: ~0.1% per tick → 100% in ~1000 ticks (~16.7 hrs)
    const fertBonus = 1 + (Number(farm.fertilizer_level) / 200); // up to +50%
    const sizeScale = 1; // larger fields don't grow faster
    const growthIncrement = 0.1 * cropMod * fertBonus * sizeScale;

    const newGrowth = Math.min(100, Number(farm.growth_stage) + growthIncrement);

    if (newGrowth >= 100) {
      await execute(
        'UPDATE farms SET growth_stage = 100, harvest_ready = TRUE WHERE id = ?',
        [farm.id],
      );
    } else {
      await execute(
        'UPDATE farms SET growth_stage = ? WHERE id = ?',
        [newGrowth, farm.id],
      );
    }

    // Decrease fertilizer over time
    if (Number(farm.fertilizer_level) > 0) {
      await execute(
        'UPDATE farms SET fertilizer_level = GREATEST(fertilizer_level - 0.01, 0) WHERE id = ?',
        [farm.id],
      );
    }
  }
}

// ── Harvest ──────────────────────────────────────────────────────────────────

export async function harvestField(fieldId: number): Promise<{ success: boolean; message?: string; yield_tons?: number }> {
  const farms = await query<FarmRow[]>('SELECT * FROM farms WHERE id = ?', [fieldId]);
  if (farms.length === 0) return { success: false, message: 'Field not found' };
  const farm = farms[0];

  if (!farm.harvest_ready) return { success: false, message: 'Crops not ready for harvest' };

  // Check for combine harvester (vehicle at location)
  const harvesters = await query<(RowDataPacket & { id: number })[]>(
    `SELECT v.id FROM vehicles v
     WHERE v.location_id = ? AND v.is_moving = FALSE AND v.health > 0
     LIMIT 1`,
    [farm.location_id],
  );

  if (harvesters.length === 0) {
    return { success: false, message: 'No harvester/vehicle available at location' };
  }

  // Calculate yield: size in hectares * base yield (2 tons/hectare for crops)
  const baseYield = Number(farm.size) * 2;
  const yieldTons = baseYield;

  await addResource(farm.location_id, 'crops', yieldTons);

  // Reset field
  await execute(
    `UPDATE farms SET growth_stage = 0, harvest_ready = FALSE, fertilizer_level = 0,
       harvest_date = NOW(), planted_date = NULL WHERE id = ?`,
    [fieldId],
  );

  return { success: true, yield_tons: yieldTons };
}

// ── Spoilage ─────────────────────────────────────────────────────────────────

export async function processSpoilage(tick: number): Promise<void> {
  await ensureFarmColumns();

  // Unharvested ready crops deteriorate
  const readyFarms = await query<(FarmRow & { spoilage_ticks: number })[]>(
    'SELECT * FROM farms WHERE harvest_ready = TRUE',
  );

  for (const farm of readyFarms) {
    const spoilage = (farm.spoilage_ticks ?? 0) + 1;
    // After 3 days (4320 ticks) of not harvesting, crops are lost
    if (spoilage >= 4320) {
      await execute(
        `UPDATE farms SET growth_stage = 0, harvest_ready = FALSE, fertilizer_level = 0,
           spoilage_ticks = 0, planted_date = NULL WHERE id = ?`,
        [farm.id],
      );
    } else {
      await execute('UPDATE farms SET spoilage_ticks = ? WHERE id = ?', [spoilage, farm.id]);
    }
  }
}

// ── Livestock ────────────────────────────────────────────────────────────────

export async function processLivestock(tick: number): Promise<void> {
  // Livestock farms consume crops and produce livestock
  // This is handled by processProduction in resourceService for buildings with
  // the livestock_farm recipe. Here we handle livestock feeding requirements.

  // Find all locations with livestock
  const livestockRows = await query<(RowDataPacket & { location_id: number; quantity: number })[]>(
    `SELECT lr.location_id, lr.quantity FROM location_resources lr
     JOIN resources r ON r.id = lr.resource_id
     WHERE r.name = 'livestock' AND lr.quantity > 0`,
  );

  for (const row of livestockRows) {
    // Each livestock unit consumes 0.001 tons of crops per tick
    const feedNeeded = Number(row.quantity) * 0.001;
    const result = await removeResource(row.location_id, 'crops', feedNeeded);
    if (!result.success) {
      // Livestock dies without food (lose 1% per tick without feed)
      await removeResource(row.location_id, 'livestock', Number(row.quantity) * 0.01);
    }
  }
}
