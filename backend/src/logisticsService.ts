import { RowDataPacket } from 'mysql2/promise';
import { query, execute, getConnection } from './databaseConfig.js';
import { BREAKDOWN, CONTAINER_TYPES, TICKS_PER_DAY } from './gameConstants.js';
import { getTravelModifier, getCurrentWeather } from './weatherService.js';
import { removeResource, addResource } from './resourceService.js';

// ── Types ────────────────────────────────────────────────────────────────────
interface VehicleRow extends RowDataPacket {
  id: number;
  vehicle_type_id: number;
  location_id: number | null;
  team: 'blue' | 'red';
  name: string | null;
  health: number;
  fuel_level: number;
  current_route: string | null;
  destination_location_id: number | null;
  departure_time: string | null;
  arrival_time: string | null;
  is_moving: boolean;
}

interface VehicleTypeRow extends RowDataPacket {
  id: number;
  name: string;
  category: string;
  capacity: number;
  fuel_type: string;
  fuel_consumption: number;
  max_speed: number;
  wear_rate: number;
}

interface ContainerRow extends RowDataPacket {
  id: number;
  type: string;
  location_id: number | null;
  vehicle_id: number | null;
  contents: string | null;
  health: number;
  is_loaded: boolean;
}

interface RouteRow extends RowDataPacket {
  id: number;
  name: string;
  start_location_id: number;
  end_location_id: number;
  distance: number;
  transport_type: string;
  team: string;
  is_active: boolean;
}

interface ScheduledLogisticsRow extends RowDataPacket {
  id: number;
  route_id: number;
  resource_id: number;
  threshold_quantity: number;
  transport_quantity: number;
  frequency_hours: number;
  is_active: boolean;
  last_run: string | null;
}

// Ensure breakdown tracking table exists
async function ensureBreakdownTable(): Promise<void> {
  await execute(`
    CREATE TABLE IF NOT EXISTS breakdowns (
      id INT PRIMARY KEY AUTO_INCREMENT,
      vehicle_id INT NOT NULL,
      route_id INT NULL,
      start_tick BIGINT NOT NULL,
      cleared BOOLEAN DEFAULT FALSE,
      cleared_tick BIGINT NULL,
      FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
  `);
}

// ── Vehicle CRUD ─────────────────────────────────────────────────────────────

export async function createVehicle(
  team: 'blue' | 'red',
  vehicleTypeId: number,
  locationId: number,
  name?: string,
): Promise<number> {
  const result = await execute(
    `INSERT INTO vehicles (vehicle_type_id, location_id, team, name, health, fuel_level)
     VALUES (?, ?, ?, ?, 100, 0)`,
    [vehicleTypeId, locationId, team, name ?? null],
  );
  return result.insertId;
}

export async function createTrain(
  team: 'blue' | 'red',
  locomotiveTypeId: number,
  _wagonIds: number[],
  locationId: number,
): Promise<number> {
  // Locomotive is a vehicle; wagons tracked as JSON on the locomotive
  const result = await execute(
    `INSERT INTO vehicles (vehicle_type_id, location_id, team, name, health, fuel_level, current_route)
     VALUES (?, ?, ?, 'Train', 100, 0, ?)`,
    [locomotiveTypeId, locationId, team, JSON.stringify({ wagons: _wagonIds })],
  );
  return result.insertId;
}

// ── Route management ─────────────────────────────────────────────────────────

export async function assignRoute(vehicleId: number, routeId: number): Promise<void> {
  const routes = await query<RouteRow[]>('SELECT * FROM routes WHERE id = ?', [routeId]);
  if (routes.length === 0) throw new Error('Route not found');
  await execute(
    'UPDATE vehicles SET current_route = ? WHERE id = ?',
    [JSON.stringify({ routeId }), vehicleId],
  );
}

export async function startTrip(
  vehicleId: number,
  destinationLocationId: number,
  cargo?: { resourceName: string; quantity: number }[],
): Promise<{ success: boolean; message?: string }> {
  const vehicles = await query<VehicleRow[]>('SELECT * FROM vehicles WHERE id = ?', [vehicleId]);
  if (vehicles.length === 0) return { success: false, message: 'Vehicle not found' };
  const v = vehicles[0];
  if (v.is_moving) return { success: false, message: 'Vehicle already moving' };

  // Load cargo from origin location
  if (cargo && v.location_id) {
    for (const c of cargo) {
      const result = await removeResource(v.location_id, c.resourceName, c.quantity);
      if (!result.success) return { success: false, message: `Cannot load ${c.resourceName}: ${result.message}` };
    }
  }

  // Calculate distance from route if available
  let distance = 100; // default
  if (v.location_id) {
    const routes = await query<RouteRow[]>(
      `SELECT * FROM routes
       WHERE (start_location_id = ? AND end_location_id = ?)
          OR (start_location_id = ? AND end_location_id = ?)
       LIMIT 1`,
      [v.location_id, destinationLocationId, destinationLocationId, v.location_id],
    );
    if (routes.length > 0) distance = routes[0].distance;
  }

  // Get vehicle type for speed
  const types = await query<VehicleTypeRow[]>(
    'SELECT * FROM vehicle_types WHERE id = ?',
    [v.vehicle_type_id],
  );
  const vType = types[0];

  const weatherMod = getTravelModifier(getCurrentWeather());
  const effectiveSpeed = vType.max_speed * weatherMod;
  const travelTimeTicks = Math.ceil((distance / effectiveSpeed) * 60); // distance/speed in hours → ticks

  const tickRows = await query<(RowDataPacket & { current_tick: number })[]>(
    'SELECT current_tick FROM game_state WHERE id = 1',
  );
  const currentTick = Number(tickRows[0]?.current_tick ?? 0);

  await execute(
    `UPDATE vehicles SET
       is_moving = TRUE,
       destination_location_id = ?,
       departure_time = NOW(),
       arrival_time = DATE_ADD(NOW(), INTERVAL ? MINUTE),
       current_route = ?
     WHERE id = ?`,
    [
      destinationLocationId,
      travelTimeTicks,
      JSON.stringify({ cargo: cargo ?? [], startTick: currentTick, arrivalTick: currentTick + travelTimeTicks, distance }),
      vehicleId,
    ],
  );

  return { success: true };
}

// ── Movement processing ──────────────────────────────────────────────────────

export async function processMovement(tick: number): Promise<void> {
  // Find vehicles that should have arrived
  const movingVehicles = await query<VehicleRow[]>(
    'SELECT * FROM vehicles WHERE is_moving = TRUE',
  );

  for (const v of movingVehicles) {
    let routeData: { arrivalTick?: number; cargo?: { resourceName: string; quantity: number }[] } = {};
    try {
      routeData = v.current_route ? JSON.parse(v.current_route) : {};
    } catch { /* ignore parse errors */ }

    const arrivalTick = routeData.arrivalTick ?? 0;
    if (tick >= arrivalTick && v.destination_location_id) {
      // Arrived: unload cargo
      if (routeData.cargo) {
        for (const c of routeData.cargo) {
          await addResource(v.destination_location_id, c.resourceName, c.quantity);
        }
      }

      await execute(
        `UPDATE vehicles SET
           is_moving = FALSE,
           location_id = ?,
           destination_location_id = NULL,
           current_route = NULL
         WHERE id = ?`,
        [v.destination_location_id, v.id],
      );
    }
  }
}

// ── Travel calculations ──────────────────────────────────────────────────────

export function calculateTravelTime(distance: number, speed: number, weatherModifier: number, _weight: number): number {
  const effectiveSpeed = speed * weatherModifier;
  if (effectiveSpeed <= 0) return Infinity;
  return Math.ceil((distance / effectiveSpeed) * 60); // ticks
}

export function calculateFuelConsumption(distance: number, _weight: number, fuelConsumptionRate: number): number {
  return distance * fuelConsumptionRate;
}

// ── Wear and tear ────────────────────────────────────────────────────────────

export async function processWearAndTear(vehicleId: number, distance: number): Promise<void> {
  const vehicles = await query<VehicleRow[]>('SELECT * FROM vehicles WHERE id = ?', [vehicleId]);
  if (vehicles.length === 0) return;

  const types = await query<VehicleTypeRow[]>(
    'SELECT * FROM vehicle_types WHERE id = ?',
    [vehicles[0].vehicle_type_id],
  );
  if (types.length === 0) return;

  const wearDamage = distance * Number(types[0].wear_rate);
  const newHealth = Math.max(0, Number(vehicles[0].health) - wearDamage);

  await execute('UPDATE vehicles SET health = ? WHERE id = ?', [newHealth, vehicleId]);
}

// ── Breakdowns ───────────────────────────────────────────────────────────────

export async function processBreakdowns(tick: number): Promise<void> {
  await ensureBreakdownTable();

  // Vehicles with low health have a chance of breaking down each tick
  const movingVehicles = await query<VehicleRow[]>(
    'SELECT * FROM vehicles WHERE is_moving = TRUE AND health < 30',
  );

  for (const v of movingVehicles) {
    const breakdownChance = (30 - Number(v.health)) / 1000; // up to 3% at 0 health
    if (Math.random() < breakdownChance) {
      // Breakdown!
      await execute(
        `UPDATE vehicles SET is_moving = FALSE WHERE id = ?`,
        [v.id],
      );
      await execute(
        `INSERT INTO breakdowns (vehicle_id, route_id, start_tick) VALUES (?, NULL, ?)`,
        [v.id, tick],
      );
    }
  }

  // Clear old breakdowns
  const breakdowns = await query<(RowDataPacket & { id: number; start_tick: number })[]>(
    'SELECT id, start_tick FROM breakdowns WHERE cleared = FALSE',
  );
  for (const bd of breakdowns) {
    if (tick - Number(bd.start_tick) >= BREAKDOWN.clearance_ticks) {
      await execute('UPDATE breakdowns SET cleared = TRUE, cleared_tick = ? WHERE id = ?', [tick, bd.id]);
    }
  }
}

export async function clearBreakdown(breakdownId: number): Promise<void> {
  await ensureBreakdownTable();
  const tickRows = await query<(RowDataPacket & { current_tick: number })[]>(
    'SELECT current_tick FROM game_state WHERE id = 1',
  );
  const tick = Number(tickRows[0]?.current_tick ?? 0);
  await execute(
    'UPDATE breakdowns SET cleared = TRUE, cleared_tick = ? WHERE id = ?',
    [tick, breakdownId],
  );
}

// ── Container operations ─────────────────────────────────────────────────────

export async function loadContainer(
  containerId: number,
  vehicleId: number,
  resourceName: string,
  quantity: number,
): Promise<{ success: boolean; message?: string }> {
  const containers = await query<ContainerRow[]>('SELECT * FROM containers WHERE id = ?', [containerId]);
  if (containers.length === 0) return { success: false, message: 'Container not found' };
  const ctr = containers[0];
  if (ctr.is_loaded) return { success: false, message: 'Container already loaded' };

  // Remove resource from container's location
  if (ctr.location_id) {
    const result = await removeResource(ctr.location_id, resourceName, quantity);
    if (!result.success) return { success: false, message: result.message };
  }

  await execute(
    `UPDATE containers SET vehicle_id = ?, location_id = NULL, is_loaded = TRUE, contents = ?
     WHERE id = ?`,
    [vehicleId, JSON.stringify({ resource: resourceName, quantity }), containerId],
  );

  return { success: true };
}

export async function unloadContainer(
  containerId: number,
  locationId: number,
): Promise<{ success: boolean; message?: string }> {
  const containers = await query<ContainerRow[]>('SELECT * FROM containers WHERE id = ?', [containerId]);
  if (containers.length === 0) return { success: false, message: 'Container not found' };
  const ctr = containers[0];
  if (!ctr.is_loaded || !ctr.contents) return { success: false, message: 'Container is empty' };

  let contents: { resource: string; quantity: number };
  try {
    contents = typeof ctr.contents === 'string' ? JSON.parse(ctr.contents) : ctr.contents;
  } catch {
    return { success: false, message: 'Invalid container contents' };
  }

  await addResource(locationId, contents.resource, contents.quantity);

  await execute(
    `UPDATE containers SET vehicle_id = NULL, location_id = ?, is_loaded = FALSE, contents = NULL
     WHERE id = ?`,
    [locationId, containerId],
  );

  return { success: true };
}

// ── Scheduled logistics ──────────────────────────────────────────────────────

export async function processScheduledLogistics(tick: number): Promise<void> {
  const schedules = await query<ScheduledLogisticsRow[]>(
    'SELECT * FROM scheduled_logistics WHERE is_active = TRUE',
  );

  for (const sched of schedules) {
    // Check if enough time has passed since last run
    const freqTicks = sched.frequency_hours * 60;
    const lastRunTick = sched.last_run ? 0 : 0; // simplified: use a tick-based approach
    if (tick % freqTicks !== 0) continue;

    // Get route info
    const routes = await query<RouteRow[]>('SELECT * FROM routes WHERE id = ?', [sched.route_id]);
    if (routes.length === 0) continue;
    const route = routes[0];

    // Check if resource at start location exceeds threshold
    const [resRows] = await query<(RowDataPacket & { quantity: number; name: string })[]>(
      `SELECT lr.quantity, r.name FROM location_resources lr
       JOIN resources r ON r.id = lr.resource_id
       WHERE lr.location_id = ? AND lr.resource_id = ?`,
      [route.start_location_id, sched.resource_id],
    ) as unknown as [(RowDataPacket & { quantity: number; name: string })[]];

    // Simplified: query directly
    const stockRows = await query<(RowDataPacket & { quantity: number; name: string })[]>(
      `SELECT lr.quantity, r.name FROM location_resources lr
       JOIN resources r ON r.id = lr.resource_id
       WHERE lr.location_id = ? AND lr.resource_id = ?`,
      [route.start_location_id, sched.resource_id],
    );

    if (stockRows.length === 0) continue;
    const stock = Number(stockRows[0].quantity);
    const resourceName = stockRows[0].name;

    if (stock >= Number(sched.threshold_quantity)) {
      const transportQty = Math.min(Number(sched.transport_quantity), stock);

      // Find an available vehicle at the start location
      const vehicles = await query<VehicleRow[]>(
        `SELECT v.* FROM vehicles v
         JOIN vehicle_types vt ON vt.id = v.vehicle_type_id
         WHERE v.location_id = ? AND v.team = ? AND v.is_moving = FALSE AND v.health > 10
         LIMIT 1`,
        [route.start_location_id, route.team],
      );

      if (vehicles.length > 0) {
        await startTrip(vehicles[0].id, route.end_location_id, [
          { resourceName, quantity: transportQty },
        ]);

        await execute(
          'UPDATE scheduled_logistics SET last_run = NOW() WHERE id = ?',
          [sched.id],
        );
      }
    }
  }
}
