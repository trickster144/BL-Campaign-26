// src/config - database pool and helpers
// Placed in src/ because src/config/ directory doesn't exist yet.
// Move to src/config/database.ts when directory is created.

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

export async function query<T extends RowDataPacket[]>(sql: string, params?: (string | number | boolean | null)[]): Promise<T> {
  const [rows] = await pool.execute<T>(sql, params || []);
  return rows;
}

export async function execute(sql: string, params?: (string | number | boolean | null)[]): Promise<ResultSetHeader> {
  const [result] = await pool.execute<ResultSetHeader>(sql, params || []);
  return result;
}

export async function getConnection(): Promise<PoolConnection> {
  return pool.getConnection();
}

export default pool;
