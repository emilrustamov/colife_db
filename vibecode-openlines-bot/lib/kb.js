import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const MEMORY_PATH = path.join(__dirname, '..', 'kb', 'memory.txt');

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
 * Company memory only. Live apartments come from search tools, not prompt dump.
 *
 * @returns {string}
 */
export function loadKnowledgeBase() {
  return (
    readFileSafe(MEMORY_PATH).trim()
    || 'Colife AE — long-term rental of rooms and apartments in Dubai. Website: https://colife.ae/catalog'
  );
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
