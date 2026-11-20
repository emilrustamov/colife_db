/**
 * Replay Wikibot chat userTurns against OL Bot (CRM off) and compare replies.
 * Usage:
 *   node scripts/wb-compare-replay.mjs
 *   node scripts/wb-compare-replay.mjs --batch=1
 *   node scripts/wb-compare-replay.mjs --only=553810
 *   node scripts/wb-compare-replay.mjs --batch=2 --after=553646
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createBotWorker } from '../lib/bot.js';
import { setVibeTestHooks } from '../lib/vibe.js';
import { enrichStateForLeave, isRobotOnlyMessage } from '../lib/scenarios.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(__dirname, '..');
const compareDir = path.join(root, 'data/state/wb-compare');

function loadEnvFile() {
  const envPath = path.join(root, '.env');
  if (!fs.existsSync(envPath)) return;
  for (const line of fs.readFileSync(envPath, 'utf8').split('\n')) {
    const m = line.match(/^([A-Z0-9_]+)=(.*)$/);
    if (!m) continue;
    if (process.env[m[1]] == null) process.env[m[1]] = m[2];
  }
}

loadEnvFile();
process.env.CRM_ENABLED = '0';

const apiKey = process.env.VIBE_API_KEY;
const botId = process.env.BOT_ID || '43591';
const model = process.env.AI_MODEL || 'bitrix/bitrixgpt-5.5-thinking';
if (!apiKey) {
  console.error('VIBE_API_KEY required');
  process.exit(1);
}

const args = process.argv.slice(2);
const batchArg = args.find((a) => a.startsWith('--batch='));
const prefixArg = args.find((a) => a.startsWith('--prefix='));
const onlyArg = args.find((a) => a.startsWith('--only='));
const afterArg = args.find((a) => a.startsWith('--after='));
const batch = batchArg ? Number(batchArg.split('=')[1]) : 1;
const prefix = prefixArg ? prefixArg.split('=')[1] : 'batch';
const only = onlyArg ? onlyArg.split('=')[1] : null;
const after = afterArg ? afterArg.split('=')[1] : null;

const inputPath = path.join(compareDir, `${prefix}${batch}-input.json`);
if (!fs.existsSync(inputPath)) {
  console.error('missing', inputPath);
  process.exit(1);
}
const input = JSON.parse(fs.readFileSync(inputPath, 'utf8'));
let chats = input.chats || [];
if (only) chats = chats.filter((c) => String(c.chatId) === String(only));
if (after) {
  const idx = chats.findIndex((c) => String(c.chatId) === String(after));
  chats = idx >= 0 ? chats.slice(idx + 1) : chats;
}

const outPath = path.join(compareDir, `${prefix}${batch}-results.json`);
const mergePrior = Boolean(after || only);
const priorResults =
  mergePrior && fs.existsSync(outPath)
    ? (JSON.parse(fs.readFileSync(outPath, 'utf8')).results || []).filter(
        (r) => !chats.some((c) => String(c.chatId) === String(r.chatId)),
      )
    : [];

const CYR = /[А-Яа-яЁё]/;

function makeEvent(dialogId, chatNumericId, text, n) {
  return {
    type: 'ONIMBOTV2MESSAGEADD',
    eventId: `${dialogId}-${n}-${Date.now()}`,
    data: {
      bot: { id: Number(botId) },
      // Unique per Wikibot chat — restoreDialogFromDb keys by chat_id and must not collide.
      chat: { id: chatNumericId, dialogId },
      message: {
        id: Date.now() + n + Math.floor(Math.random() * 1000),
        text,
        authorId: 111,
        date: new Date().toISOString(),
        isSystem: false,
      },
    },
  };
}

function langOf(s) {
  return CYR.test(s) ? 'ru' : 'en';
}

function analyze(req, ours, handedOff) {
  const notes = [];
  // Intentional silent ack for robot-only templates is not a compare failure.
  if (!String(ours || '').trim() && !isRobotOnlyMessage(req)) {
    notes.push(handedOff ? 'silent_handoff' : 'empty_our_reply');
  }
  if (langOf(req) === 'en' && CYR.test(ours || '')) notes.push('lang_ru_after_en');
  if (
    langOf(req) === 'ru' &&
    String(ours || '').trim() &&
    !CYR.test(ours) &&
    /[A-Za-z]{4,}/.test(ours)
  ) {
    notes.push('lang_en_after_ru');
  }
  const flat = String(ours || '').replace(/\n/g, ' ');
  if ((flat.match(/[?？]/g) || []).length >= 2) notes.push('multi_question');
  if (/could not answer|не смог ответить|I cannot answer/i.test(ours || '')) {
    notes.push('ai_fallback');
  }
  return notes;
}

async function replayChat(chat) {
  const outbound = [];
  let handedOff = false;
  setVibeTestHooks({
    async sendMessage(_k, _b, _d, fields) {
      outbound.push(String(fields?.message || ''));
      return { success: true };
    },
    async handoffToOperator() {
      handedOff = true;
      return { success: true };
    },
  });

  const worker = createBotWorker({
    apiKey,
    botId,
    model,
    pollMs: 60000,
    catalogRefreshMs: 86400000,
  });
  const runStamp = Date.now();
  const dialogId = `wb-cmp-${chat.chatId}-${runStamp}`;
  // Must be unique per run: restoreDialogFromDb keys by chat_id and would revive prior handoffs.
  const chatNumericId = Number(`${String(chat.chatId)}${String(runStamp).slice(-6)}`);
  const state = worker.store.get(dialogId);
  const turns = chat.userTurns || [];
  const compared = [];

  for (let i = 0; i < turns.length; i++) {
    const t = turns[i];
    const req = t.request;
    const before = outbound.length;
    const started = Date.now();
    await worker.handleMessage(makeEvent(dialogId, chatNumericId, req, i + 1));
    const replies = outbound
      .slice(before)
      .filter((m) => !m.startsWith('Operator summary:'));
    const ours = replies.join('\n');
    const notes = analyze(req, ours, handedOff || state.handedOff);
    compared.push({
      n: i + 1,
      request: req,
      wiki: t.wikiResponse || '',
      ours,
      explain: t.explain,
      ms: Date.now() - started,
      handedOff: handedOff || !!state.handedOff,
      notes,
      category: state.category,
      scenario: state.activeScenario,
    });

    const flag = notes.length ? `WARN ${notes.join(',')}` : 'OK';
    console.log(`\n[${chat.chatId}] #${i + 1} ${flag} (${Date.now() - started}ms)`);
    console.log('USER:', String(req).slice(0, 180).replace(/\n/g, ' | '));
    console.log('WIKI:', String(t.wikiResponse || '').slice(0, 220).replace(/\n/g, ' | '));
    console.log('OURS:', (ours || '(empty)').slice(0, 220).replace(/\n/g, ' | '));

    if (handedOff || state.handedOff) break;
  }

  enrichStateForLeave(state);
  setVibeTestHooks(null);

  const systemic = compared
    .flatMap((c) => c.notes)
    .filter((n) => ['empty_our_reply', 'ai_fallback', 'silent_handoff'].includes(n));

  return {
    chatId: chat.chatId,
    messageCount: chat.messageCount,
    wasEscalated: chat.wasEscalated,
    finalCategory: state.category,
    finalScenario: state.activeScenario,
    compared,
    systemic,
  };
}

const results = [...priorResults];
let stopSystemic = null;

for (const chat of chats) {
  console.log(`\n======== chat ${chat.chatId} ========`);
  const r = await replayChat(chat);
  results.push(r);
  // Persist incrementally so a mid-batch fix keeps prior chats.
  fs.writeFileSync(
    outPath,
    JSON.stringify(
      { at: new Date().toISOString(), model, batch, stoppedOnSystemic: stopSystemic, results },
      null,
      2,
    ),
  );
  if (r.systemic.length) {
    console.log('SYSTEMIC FLAGS:', r.systemic);
    stopSystemic = { chatId: r.chatId, flags: r.systemic };
    fs.writeFileSync(
      outPath,
      JSON.stringify(
        { at: new Date().toISOString(), model, batch, stoppedOnSystemic: stopSystemic, results },
        null,
        2,
      ),
    );
    break;
  }
}

console.log('\n======== BATCH SUMMARY ========');
for (const r of results) {
  const warns = r.compared.flatMap((c) => c.notes);
  console.log(
    `${r.chatId} turns=${r.compared.length} cat=${r.finalCategory || '—'} scen=${r.finalScenario || '—'} warns=${warns.join('|') || 'none'}`,
  );
}
if (stopSystemic) {
  console.log('\nSTOPPED on systemic:', JSON.stringify(stopSystemic));
  process.exit(2);
}
console.log(`\nWrote ${outPath}`);
console.log('PAUSE: review batch, then continue with next 10.');
// Vibe/bot keep sockets open — force exit so batch loops don't hang.
process.exit(0);
