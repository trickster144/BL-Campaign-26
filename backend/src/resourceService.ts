import { RowDataPacket } from 'mysql2/promise';
import { query, execute, getConnection } from './databaseConfig.js';
import { PRODUCTION_RECIPES, RESOURCES } from './gameConstants.js';

// ── Types ────────────────────────────────────────────────────────────────────
export interface LocationResource extends RowDataPacket {
  id: number;
  location_id: number;
  resource_id: number;
  quantity: number;
  max_capacity: number | null;
  resource_name: string;
  category: string;
}

// ── Read helpers ─────────────────────────────────────────────────────────────

export async function getLocationResources(locationId: number): Promise<LocationResource[]> {
  return query<LocationResource[]>(
    `SELECT lr.*, r.name AS resource_name, r.category
     FROM location_resources lr
     JOIN resources r ON r.id = lr.resource_id
     WHERE lr.location_id = ?`,
    [locationId],
  );
}

export async function getResourceQuantity(locationId: number, resourceName: string): Promise<number> {
  const rows = await query<(RowDataPacket & { quantity: number })[]>(
    `SELECT lr.quantity
     FROM location_resources lr
     JOIN resources r ON r.id = lr.resource_id
     WHERE lr.location_id = ? AND r.name = ?`,
    [locationId, resourceName],
  );
  return rows.length > 0 ? Number(rows[0].quantity) : 0;
}

// ── Mutation helpers ─────────────────────────────────────────────────────────

export async function addResource(
  locationId: number,
  resourceName: string,
  quantity: number,
): Promise<{ success: boolean; newQuantity: number; message?: string }> {
  const conn = await getConnection();
  try {
    await conn.beginTransaction();

    // Resolve resource id
    const [resRows] = await conn.execute<(RowDataPacket & { id: number })[]>(
      'SELECT id FROM resources WHERE name = ?',
      [resourceName],
    );
    if (resRows.length === 0) {
      await conn.rollback();
      return { success: false, newQuantity: 0, message: `Unknown resource: ${resourceName}` };
    }
    const resourceId = resRows[0].id;

    // Upsert
    const [existing] = await conn.execute<(RowDataPacket & { quantity: number; max_capacity: number | null })[]>(
      'SELECT quantity, max_capacity FROM location_resources WHERE location_id = ? AND resource_id = ? FOR UPDATE',
      [locationId, resourceId],
    );

    let newQty: number;
    if (existing.length > 0) {
      const current = Number(existing[0].quantity);
      const cap = existing[0].max_capacity ? Number(existing[0].max_capacity) : Infinity;
      newQty = Math.min(current + quantity, cap);
      await conn.execute(
        'UPDATE location_resources SET quantity = ? WHERE location_id = ? AND resource_id = ?',
        [newQty, locationId, resourceId],
      );
    } else {
      newQty = quantity;
      await conn.execute(
        'INSERT INTO location_resources (location_id, resource_id, quantity) VALUES (?, ?, ?)',
        [locationId, resourceId, newQty],
      );
    }

    await conn.commit();
    return { success: true, newQuantity: newQty };
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}

export async function removeResource(
  locationId: number,
  resourceName: string,
  quantity: number,
): Promise<{ success: boolean; newQuantity: number; message?: string }> {
  const conn = await getConnection();
  try {
    await conn.beginTransaction();

    const [resRows] = await conn.execute<(RowDataPacket & { id: number })[]>(
      'SELECT id FROM resources WHERE name = ?',
      [resourceName],
    );
    if (resRows.length === 0) {
      await conn.rollback();
      return { success: false, newQuantity: 0, message: `Unknown resource: ${resourceName}` };
    }
    const resourceId = resRows[0].id;

    const [existing] = await conn.execute<(RowDataPacket & { quantity: number })[]>(
      'SELECT quantity FROM location_resources WHERE location_id = ? AND resource_id = ? FOR UPDATE',
      [locationId, resourceId],
    );

    const current = existing.length > 0 ? Number(existing[0].quantity) : 0;
    if (current < quantity) {
      await conn.rollback();
      return { success: false, newQuantity: current, message: 'Insufficient resources' };
    }

    const newQty = current - quantity;
    await conn.execute(
      'UPDATE location_resources SET quantity = ? WHERE location_id = ? AND resource_id = ?',
      [newQty, locationId, resourceId],
    );

    await conn.commit();
    return { success: true, newQuantity: newQty };
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}

export async function transferResource(
  fromLocationId: number,
  toLocationId: number,
  resourceName: string,
  quantity: number,
): Promise<{ success: boolean; message?: string }> {
  const removal = await removeResource(fromLocationId, resourceName, quantity);
  if (!removal.success) return { success: false, message: removal.message };

  const addition = await addResource(toLocationId, resourceName, quantity);
  if (!addition.success) {
    // rollback the removal
    await addResource(fromLocationId, resourceName, quantity);
    return { success: false, message: addition.message };
  }
  return { success: true };
}

// ── Production ───────────────────────────────────────────────────────────────

interface BuildingRow extends RowDataPacket {
  id: number;
  location_id: number;
  type: string;
  level: number;
  health: number;
  is_operational: boolean;
  workers_assigned: number;
}

export async function processProduction(buildingId: number): Promise<boolean> {
  const buildings = await query<BuildingRow[]>(
    'SELECT * FROM buildings WHERE id = ? AND is_operational = TRUE',
    [buildingId],
  );
  if (buildings.length === 0) return false;

  const bld = buildings[0];
  const recipe = PRODUCTION_RECIPES[bld.type];
  if (!recipe) return false; // building type has no recipe

  const locationId = bld.location_id;
  const efficiencyMultiplier = (bld.level || 1) * (Number(bld.health) / 100);

  // Check all inputs available
  for (const input of recipe.inputs) {
    const available = await getResourceQuantity(locationId, input.resource);
    if (available < input.qty) return false;
  }

  // Consume inputs
  for (const input of recipe.inputs) {
    const result = await removeResource(locationId, input.resource, input.qty);
    if (!result.success) return false;
  }

  // Produce outputs
  for (const output of recipe.outputs) {
    const def = RESOURCES[output.resource];
    if (def && def.canStore === false) continue; // non-storable (e.g. asphalt, concrete) – used immediately at construction site
    await addResource(locationId, output.resource, output.qty * efficiencyMultiplier);
  }

  return true;
}

export async function processAllProduction(tick: number): Promise<number> {
  const buildings = await query<BuildingRow[]>(
    `SELECT * FROM buildings
     WHERE is_operational = TRUE
       AND construction_completed IS NOT NULL`,
  );

  let produced = 0;
  for (const bld of buildings) {
    const recipe = PRODUCTION_RECIPES[bld.type];
    if (!recipe) continue;
    const ok = await processProduction(bld.id);
    if (ok) produced++;
  }
  return produced;
}
