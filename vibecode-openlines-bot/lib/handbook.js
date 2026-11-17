import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadApartments } from './catalog.js';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');

/**
 * @param {string} name
 * @returns {object}
 */
function readJson(name) {
  const raw = fs.readFileSync(path.join(ROOT, 'handbook', name), 'utf8');
  return JSON.parse(raw);
}

const areasBook = readJson('areas.json');
const crmBook = readJson('crm.json');

/**
 * @returns {{ aliases: Record<string, string>, names: string[] }}
 */
export function loadAreasHandbook() {
  return areasBook;
}

/**
 * @returns {object}
 */
export function loadCrmHandbook() {
  return crmBook;
}

/**
 * Area directory: catalog DISTINCT + optional handbook names + aliases.
 *
 * @returns {{ names: string[], aliases: Record<string, string> }}
 */
export function areaDirectory() {
  const aliases = { ...(areasBook.aliases || {}) };
  /** @type {Set<string>} */
  const names = new Set((areasBook.names || []).map(String));
  for (const item of loadApartments()) {
    const area = String(item.area || '').trim();
    if (area) names.add(area);
  }
  // Alias keys also act as match needles (short forms).
  for (const key of Object.keys(aliases)) names.add(key);
  return { names: [...names], aliases };
}

/**
 * Match client text against catalog areas + aliases.
 *
 * @param {string} text
 * @returns {string[]}
 */
export function matchAreasInText(text) {
  const lower = String(text || '').toLowerCase();
  const { names, aliases } = areaDirectory();
  /** @type {Array<{ needle: string, out: string }>} */
  const needles = [];
  for (const name of names) {
    const key = String(name).toLowerCase();
    needles.push({ needle: key, out: aliases[key] || name });
  }
  needles.sort((a, b) => b.needle.length - a.needle.length);

  /** @type {string[]} */
  const area = [];
  for (const { needle, out } of needles) {
    if (!needle || !lower.includes(needle)) continue;
    if (!area.some((a) => a.toLowerCase() === String(out).toLowerCase())) {
      area.push(out);
    }
  }
  return area;
}
