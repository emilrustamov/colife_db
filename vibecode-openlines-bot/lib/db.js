import mysql from 'mysql2/promise';

/** @type {import('mysql2/promise').Pool|null} */
let pool = null;

/**
 * @returns {import('mysql2/promise').Pool|null}
 */
export function getPool() {
  if (pool) return pool;
  const host = process.env.DB_HOST || '';
  const user = process.env.DB_USERNAME || process.env.DB_USER || '';
  const password = process.env.DB_PASSWORD || '';
  const database = process.env.DB_DATABASE || '';
  if (!host || !database || !user) return null;
  pool = mysql.createPool({
    host,
    port: Number(process.env.DB_PORT || 3306),
    user,
    password,
    database,
    waitForConnections: true,
    connectionLimit: 5,
    namedPlaceholders: true,
  });
  return pool;
}

/**
 * @param {string} sql
 * @param {object|array} [params]
 * @returns {Promise<any>}
 */
export async function query(sql, params = {}) {
  const db = getPool();
  if (!db) throw new Error('DB is not configured');
  const [rows] = await db.execute(sql, params);
  return rows;
}
