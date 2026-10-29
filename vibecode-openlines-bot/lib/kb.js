import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const MEMORY_PATH = path.join(__dirname, '..', 'kb', 'memory.txt');
const CATALOG_SEED = path.join(__dirname, '..', 'kb', 'catalog.seed.txt');

/**
 * @returns {string}
 */
function catalogPath() {
  return process.env.CATALOG_PATH || '/opt/data/state/catalog.txt';
}

/**
 * @param {string} filePath
 * @returns {string}
 */
function readFileSafe(filePath) {
  try {
    return fs.readFileSync(filePath, 'utf8');
  } catch {
    return '';
  }
}

/**
 * @returns {string}
 */
export function loadKnowledgeBase() {
  const memory =
    readFileSafe(process.env.KB_MEMORY_PATH || MEMORY_PATH) ||
    'Colife AE — long-term rental of rooms and apartments in Dubai. Website: https://colife.ae/catalog';
  const catalog = readFileSafe(catalogPath()) || readFileSafe(CATALOG_SEED);
  return [memory.trim(), catalog.trim()].filter(Boolean).join('\n\n');
}

/**
 * @param {string} knowledgeBase
 * @param {string} question
 * @param {number} [maxChars]
 * @returns {string}
 */
export function pickRelevantContext(knowledgeBase, question, maxChars = 5000) {
  const text = String(knowledgeBase || '');
  if (text.length <= maxChars) return text;
  const q = String(question || '').toLowerCase();
  const tokens = q.split(/[^\p{L}\p{N}]+/u).filter((t) => t.length > 2);
  const lines = text.split(/\n+/);
  const scored = lines.map((line, idx) => {
    const lower = line.toLowerCase();
    let score = 0;
    for (const token of tokens) {
      if (lower.includes(token)) score += 1;
    }
    return { idx, score, line };
  });
  scored.sort((a, b) => b.score - a.score || a.idx - b.idx);
  const picked = [];
  let size = 0;
  for (const row of scored) {
    if (row.score === 0 && picked.length > 12) continue;
    if (size + row.line.length + 1 > maxChars) break;
    picked.push(row);
    size += row.line.length + 1;
  }
  picked.sort((a, b) => a.idx - b.idx);
  const out = picked.map((p) => p.line).join('\n').trim();
  return out || text.slice(0, maxChars);
}
