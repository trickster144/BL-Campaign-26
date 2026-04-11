import { RowDataPacket } from 'mysql2/promise';
import { query, execute, getConnection } from './databaseConfig.js';
import { RESOURCES } from './gameConstants.js';
import { addResource, removeResource } from './resourceService.js';

// ── Types ────────────────────────────────────────────────────────────────────
interface MarketPriceRow extends RowDataPacket {
  resource_id: number;
  resource_name: string;
  base_price: number;
  current_price: number;
  supply: number;
  demand: number;
}

// Ensure market price tracking table
async function ensureMarketTable(): Promise<void> {
  await execute(`
    CREATE TABLE IF NOT EXISTS market_prices (
      resource_id INT PRIMARY KEY,
      current_price DECIMAL(10,2) NOT NULL,
      supply DECIMAL(15,3) DEFAULT 0,
      demand DECIMAL(15,3) DEFAULT 0,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
    )
  `);

  // Populate with base prices if empty
  const count = await query<(RowDataPacket & { cnt: number })[]>('SELECT COUNT(*) AS cnt FROM market_prices');
  if (Number(count[0]?.cnt ?? 0) === 0) {
    await execute(`
      INSERT INTO market_prices (resource_id, current_price, supply, demand)
      SELECT id, base_price, 1000, 500 FROM resources
      ON DUPLICATE KEY UPDATE resource_id = resource_id
    `);
  }
}

// Ensure team treasury table
async function ensureTreasuryTable(): Promise<void> {
  await execute(`
    CREATE TABLE IF NOT EXISTS team_treasury (
      team ENUM('blue','red') PRIMARY KEY,
      balance DECIMAL(15,2) DEFAULT 100000.00,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
  `);
  // Seed if empty
  const count = await query<(RowDataPacket & { cnt: number })[]>('SELECT COUNT(*) AS cnt FROM team_treasury');
  if (Number(count[0]?.cnt ?? 0) === 0) {
    await execute(`INSERT IGNORE INTO team_treasury (team, balance) VALUES ('blue', 100000), ('red', 100000)`);
  }
}

// Ensure market_orders table for customs house delivery tracking
async function ensureOrdersTable(): Promise<void> {
  await execute(`
    CREATE TABLE IF NOT EXISTS market_orders (
      id INT PRIMARY KEY AUTO_INCREMENT,
      team ENUM('blue','red') NOT NULL,
      resource_id INT NOT NULL,
      quantity DECIMAL(15,3) NOT NULL,
      price_per_unit DECIMAL(10,2) NOT NULL,
      total_cost DECIMAL(15,2) NOT NULL,
      order_type ENUM('buy','sell') NOT NULL,
      customs_house_id INT NULL,
      delivered BOOLEAN DEFAULT FALSE,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (resource_id) REFERENCES resources(id)
    )
  `);
}

// ── Market prices ────────────────────────────────────────────────────────────

export async function getMarketPrices(): Promise<MarketPriceRow[]> {
  await ensureMarketTable();
  return query<MarketPriceRow[]>(
    `SELECT mp.resource_id, r.name AS resource_name, r.base_price,
            mp.current_price, mp.supply, mp.demand
     FROM market_prices mp
     JOIN resources r ON r.id = mp.resource_id
     ORDER BY r.name`,
  );
}

// ── Buy / Sell ───────────────────────────────────────────────────────────────

export async function buyResource(
  team: 'blue' | 'red',
  resourceName: string,
  quantity: number,
): Promise<{ success: boolean; totalCost?: number; message?: string }> {
  await ensureMarketTable();
  await ensureTreasuryTable();
  await ensureOrdersTable();

  const conn = await getConnection();
  try {
    await conn.beginTransaction();

    // Get resource info and price
    const [priceRows] = await conn.execute<(RowDataPacket & {
      resource_id: number; current_price: number; supply: number;
    })[]>(
      `SELECT mp.resource_id, mp.current_price, mp.supply
       FROM market_prices mp
       JOIN resources r ON r.id = mp.resource_id
       WHERE r.name = ?`,
      [resourceName],
    );
    if (priceRows.length === 0) {
      await conn.rollback();
      return { success: false, message: `Resource ${resourceName} not found on market` };
    }
    const { resource_id, current_price, supply } = priceRows[0];

    if (Number(supply) < quantity) {
      await conn.rollback();
      return { success: false, message: 'Insufficient market supply' };
    }

    const totalCost = quantity * Number(current_price);

    // Check team treasury
    const [treasury] = await conn.execute<(RowDataPacket & { balance: number })[]>(
      'SELECT balance FROM team_treasury WHERE team = ? FOR UPDATE',
      [team],
    );
    if (treasury.length === 0 || Number(treasury[0].balance) < totalCost) {
      await conn.rollback();
      return { success: false, message: 'Insufficient funds' };
    }

    // Deduct funds
    await conn.execute('UPDATE team_treasury SET balance = balance - ? WHERE team = ?', [totalCost, team]);

    // Update market supply and increase demand
    await conn.execute(
      'UPDATE market_prices SET supply = supply - ?, demand = demand + ? WHERE resource_id = ?',
      [quantity, quantity, resource_id],
    );

    // Create order (delivered to customs house)
    await conn.execute(
      `INSERT INTO market_orders (team, resource_id, quantity, price_per_unit, total_cost, order_type)
       VALUES (?, ?, ?, ?, ?, 'buy')`,
      [team, resource_id, quantity, current_price, totalCost],
    );

    // Record transaction
    await conn.execute(
      `INSERT INTO market_transactions (user_id, resource_id, transaction_type, quantity, price_per_unit, total_value)
       VALUES (1, ?, 'buy', ?, ?, ?)`,
      [resource_id, quantity, current_price, totalCost],
    );

    await conn.commit();
    return { success: true, totalCost };
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}

export async function sellResource(
  team: 'blue' | 'red',
  resourceName: string,
  quantity: number,
  locationId: number,
): Promise<{ success: boolean; totalRevenue?: number; message?: string }> {
  await ensureMarketTable();
  await ensureTreasuryTable();
  await ensureOrdersTable();

  // Remove resource from location
  const removal = await removeResource(locationId, resourceName, quantity);
  if (!removal.success) return { success: false, message: removal.message };

  const conn = await getConnection();
  try {
    await conn.beginTransaction();

    const [priceRows] = await conn.execute<(RowDataPacket & {
      resource_id: number; current_price: number;
    })[]>(
      `SELECT mp.resource_id, mp.current_price
       FROM market_prices mp
       JOIN resources r ON r.id = mp.resource_id
       WHERE r.name = ?`,
      [resourceName],
    );
    if (priceRows.length === 0) {
      await conn.rollback();
      // Refund resources
      await addResource(locationId, resourceName, quantity);
      return { success: false, message: 'Resource not found on market' };
    }

    const { resource_id, current_price } = priceRows[0];
    const totalRevenue = quantity * Number(current_price) * 0.9; // 10% market fee

    // Add funds
    await conn.execute('UPDATE team_treasury SET balance = balance + ? WHERE team = ?', [totalRevenue, team]);

    // Update market supply
    await conn.execute(
      'UPDATE market_prices SET supply = supply + ?, demand = GREATEST(demand - ?, 0) WHERE resource_id = ?',
      [quantity, quantity * 0.5, resource_id],
    );

    // Record transaction
    await conn.execute(
      `INSERT INTO market_transactions (user_id, resource_id, transaction_type, quantity, price_per_unit, total_value)
       VALUES (1, ?, 'sell', ?, ?, ?)`,
      [resource_id, quantity, current_price, totalRevenue],
    );

    await conn.commit();
    return { success: true, totalRevenue };
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}

// ── Price updates (supply/demand dynamics) ───────────────────────────────────

export async function updatePrices(_tick: number): Promise<void> {
  await ensureMarketTable();

  // Price adjustment: if demand > supply, price increases; if supply > demand, price decreases
  await execute(`
    UPDATE market_prices mp
    JOIN resources r ON r.id = mp.resource_id
    SET mp.current_price = GREATEST(
      r.base_price * 0.1,
      LEAST(
        r.base_price * 10,
        mp.current_price * (1 + (mp.demand - mp.supply) / (GREATEST(mp.supply, 1) * 100))
      )
    )
  `);

  // Decay demand and replenish supply slightly each tick
  await execute(`
    UPDATE market_prices SET
      demand = GREATEST(demand * 0.999, 0),
      supply = supply + 0.1
  `);
}

// ── Customs house delivery ───────────────────────────────────────────────────

export async function deliverToCustomsHouse(orderId: number, customsHouseLocationId: number): Promise<{ success: boolean; message?: string }> {
  await ensureOrdersTable();

  const orders = await query<(RowDataPacket & {
    id: number; resource_id: number; quantity: number; delivered: boolean; order_type: string;
  })[]>(
    'SELECT * FROM market_orders WHERE id = ? AND delivered = FALSE',
    [orderId],
  );

  if (orders.length === 0) return { success: false, message: 'Order not found or already delivered' };
  const order = orders[0];

  if (order.order_type !== 'buy') return { success: false, message: 'Can only deliver buy orders' };

  // Get resource name
  const resRows = await query<(RowDataPacket & { name: string })[]>(
    'SELECT name FROM resources WHERE id = ?',
    [order.resource_id],
  );
  if (resRows.length === 0) return { success: false, message: 'Resource not found' };

  await addResource(customsHouseLocationId, resRows[0].name, Number(order.quantity));
  await execute('UPDATE market_orders SET delivered = TRUE, customs_house_id = ? WHERE id = ?', [customsHouseLocationId, orderId]);

  return { success: true };
}

// ── Vehicle purchase ─────────────────────────────────────────────────────────

export async function buyVehicle(
  team: 'blue' | 'red',
  vehicleTypeId: number,
  quantity: number,
  locationId: number,
): Promise<{ success: boolean; vehicleIds?: number[]; message?: string }> {
  await ensureTreasuryTable();

  // Get vehicle type cost (capacity * 100 as rough cost)
  const types = await query<(RowDataPacket & { id: number; capacity: number; name: string })[]>(
    'SELECT * FROM vehicle_types WHERE id = ?',
    [vehicleTypeId],
  );
  if (types.length === 0) return { success: false, message: 'Vehicle type not found' };

  const costPerUnit = Number(types[0].capacity) * 100;
  const totalCost = costPerUnit * quantity;

  const treasury = await query<(RowDataPacket & { balance: number })[]>(
    'SELECT balance FROM team_treasury WHERE team = ?',
    [team],
  );
  if (treasury.length === 0 || Number(treasury[0].balance) < totalCost) {
    return { success: false, message: 'Insufficient funds' };
  }

  await execute('UPDATE team_treasury SET balance = balance - ? WHERE team = ?', [totalCost, team]);

  const vehicleIds: number[] = [];
  for (let i = 0; i < quantity; i++) {
    const result = await execute(
      `INSERT INTO vehicles (vehicle_type_id, location_id, team, health, fuel_level)
       VALUES (?, ?, ?, 100, 0)`,
      [vehicleTypeId, locationId, team],
    );
    vehicleIds.push(result.insertId);
  }

  return { success: true, vehicleIds };
}

export async function sellVehicle(
  team: 'blue' | 'red',
  vehicleId: number,
): Promise<{ success: boolean; revenue?: number; message?: string }> {
  await ensureTreasuryTable();

  const vehicles = await query<(RowDataPacket & { id: number; vehicle_type_id: number; team: string; health: number })[]>(
    'SELECT * FROM vehicles WHERE id = ? AND team = ?',
    [vehicleId, team],
  );
  if (vehicles.length === 0) return { success: false, message: 'Vehicle not found or not owned by team' };

  const types = await query<(RowDataPacket & { capacity: number })[]>(
    'SELECT capacity FROM vehicle_types WHERE id = ?',
    [vehicles[0].vehicle_type_id],
  );

  const baseValue = Number(types[0]?.capacity ?? 10) * 100;
  const revenue = baseValue * (Number(vehicles[0].health) / 100) * 0.5; // 50% resale value

  await execute('DELETE FROM vehicles WHERE id = ?', [vehicleId]);
  await execute('UPDATE team_treasury SET balance = balance + ? WHERE team = ?', [revenue, team]);

  return { success: true, revenue };
}
