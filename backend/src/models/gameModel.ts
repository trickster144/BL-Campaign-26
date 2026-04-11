import mysql, { Pool, PoolConnection, RowDataPacket, ResultSetHeader } from 'mysql2/promise';
import dotenv from 'dotenv';

dotenv.config();

const pool: Pool = mysql.createPool({
  host: process.env.DB_HOST || '10.0.0.28',
  port: parseInt(process.env.DB_PORT || '3306'),
  user: process.env.DB_USER || 'ash_user_copilot',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'campaign_data',
  waitForConnections: true,
  connectionLimit: 20,
  queueLimit: 0,
  enableKeepAlive: true,
  keepAliveInitialDelay: 10000,
});

export async function testConnection(): Promise<boolean> {
  try {
    const conn = await pool.getConnection();
    await conn.ping();
    conn.release();
    console.log('✅ Database connection successful');
    return true;
  } catch (error) {
    console.error('❌ Database connection failed:', error);
    return false;
  }
}

export async function query<T extends RowDataPacket[]>(sql: string, params?: any[]): Promise<T> {
  const [rows] = await pool.execute<T>(sql, params);
  return rows;
}

export async function execute(sql: string, params?: any[]): Promise<ResultSetHeader> {
  const [result] = await pool.execute<ResultSetHeader>(sql, params);
  return result;
}

export async function getConnection(): Promise<PoolConnection> {
  return pool.getConnection();
}

export interface GameState {
  id: number;
  current_tick: number;
  game_started: boolean;
  start_date: string | null;
  last_tick_time: string | null;
  weather_data: any;
  global_market_prices: any;
}

export const getGameStateFromDB = async (): Promise<GameState> => {
  const rows = await query<(GameState & RowDataPacket)[]>(
    'SELECT * FROM game_state WHERE id = 1'
  );
  return rows[0];
};

export const updateGameStateInDB = async (updates: Partial<GameState>): Promise<GameState> => {
  const fields = Object.keys(updates);
  const values = Object.values(updates);
  const setClause = fields.map(field => `${field} = ?`).join(', ');
  await execute(`UPDATE game_state SET ${setClause} WHERE id = 1`, values);
  return getGameStateFromDB();
};

export default pool;