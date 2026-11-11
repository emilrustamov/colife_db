import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { query, getPool } from './db.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const KB_ROOT = path.join(__dirname, '..', 'kb');

/**
 * @param {string} rel
 * @returns {string}
 */
function readKb(rel) {
  try {
    return fs.readFileSync(path.join(KB_ROOT, rel), 'utf8');
  } catch {
    return '';
  }
}

/**
 * @returns {Record<string, string[]>}
 */
function readScopeToolsFile() {
  try {
    return JSON.parse(readKb('scope_tools.json') || '{}');
  } catch {
    return {};
  }
}

/**
 * @param {string} key
 * @param {string} kind
 * @returns {Promise<string>}
 */
export async function loadTemplate(key, kind = 'instruction') {
  const db = getPool();
  if (db) {
    try {
      const rows = await query(
        `SELECT body FROM ol_bot_template_versions
         WHERE template_key = :key AND kind = :kind AND is_active = 1
         ORDER BY id DESC LIMIT 1`,
        { key, kind },
      );
      if (rows?.[0]?.body) return String(rows[0].body);
    } catch {
      // fallback to files
    }
  }
  if (kind === 'tool') {
    return readKb(`tools/${key}.json`);
  }
  if (key === 'shared_preamble') return readKb('shared_preamble.md');
  if (key === 'main') return readKb('main.md');
  if (key.startsWith('scenario:')) return readKb(`scenarios/${key.slice(9)}.md`);
  if (key.startsWith('skill:')) return readKb(`skills/${key.slice(6)}.md`);
  return readKb(`${key}.md`);
}

/**
 * Resolve tool names for a Wikibot-like scope.
 *
 * @param {string|null|undefined} scopeKey main | scenario:x | skill:x
 * @returns {Promise<string[]>}
 */
export async function loadScopeToolNames(scopeKey = 'main') {
  const key = String(scopeKey || 'main');
  const db = getPool();
  if (db) {
    try {
      const rows = await query(
        `SELECT tool_key FROM ol_bot_scope_tools
         WHERE scope_key = :scope
         ORDER BY sort_order ASC, id ASC`,
        { scope: key },
      );
      if (rows?.length) {
        return rows.map((row) => String(row.tool_key)).filter(Boolean);
      }
    } catch {
      // fallback
    }
  }
  const fileMap = readScopeToolsFile();
  if (Array.isArray(fileMap[key]) && fileMap[key].length) {
    return fileMap[key].map(String);
  }
  return fileMap.main || [
    'search_apartments',
    'get_min_price',
    'get_apartment_by_unit_number',
    'classify_budget_target',
    'qualify_lead',
    'leave_chat',
    'set_wikibot_in_chat_status',
    'set_move_in_date',
  ];
}

/**
 * @param {string|null|undefined} scopeKey
 * @returns {Promise<object[]>}
 */
export async function loadToolSchemas(scopeKey = 'main') {
  const names = await loadScopeToolNames(scopeKey);
  const tools = [];
  for (const name of names) {
    const raw = await loadTemplate(name, 'tool');
    if (!raw) continue;
    try {
      const schema = JSON.parse(raw);
      tools.push({
        type: 'function',
        function: {
          name: schema.name,
          description: schema.description,
          parameters: schema.parameters,
        },
      });
    } catch {
      // skip bad schema
    }
  }
  return tools;
}

/**
 * @param {string|null} activeScenario
 * @returns {Promise<string>}
 */
export async function buildSystemInstruction(activeScenario = null) {
  const postponedFrom = process.env.POSTPONED_FROM_DATE || '2026-12-10';
  const parts = [
    await loadTemplate('shared_preamble'),
    `POSTPONED_FROM_DATE=${postponedFrom}`,
    await loadTemplate('main'),
  ];
  if (activeScenario) {
    const body = await loadTemplate(`scenario:${activeScenario}`);
    if (body) {
      parts.push(`\n# Active scenario: ${activeScenario}\n${body}`);
    }
  }
  return parts.filter(Boolean).join('\n\n');
}
