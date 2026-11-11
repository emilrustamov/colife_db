import { getPool, query } from './db.js';

/**
 * @param {object} row
 * @returns {Promise<void>}
 */
export async function upsertChat(row) {
  if (!getPool()) return;
  await query(
    `INSERT INTO ol_bot_chats
      (chat_id, dialog_id, deal_id, started_at, ended_at, messages_count, duration_sec,
       first_response_sec, avg_response_sec, max_response_sec, escalated, escalate_reason,
       escalate_bucket, credits, tags, category, line_id)
     VALUES
      (:chat_id, :dialog_id, :deal_id, :started_at, :ended_at, :messages_count, :duration_sec,
       :first_response_sec, :avg_response_sec, :max_response_sec, :escalated, :escalate_reason,
       :escalate_bucket, :credits, :tags, :category, :line_id)
     ON DUPLICATE KEY UPDATE
       dialog_id=COALESCE(VALUES(dialog_id), dialog_id),
       deal_id=COALESCE(VALUES(deal_id), deal_id),
       ended_at=VALUES(ended_at),
       messages_count=VALUES(messages_count),
       duration_sec=VALUES(duration_sec),
       first_response_sec=VALUES(first_response_sec),
       avg_response_sec=VALUES(avg_response_sec),
       max_response_sec=VALUES(max_response_sec),
       escalated=VALUES(escalated),
       escalate_reason=VALUES(escalate_reason),
       escalate_bucket=VALUES(escalate_bucket),
       credits=VALUES(credits),
       tags=VALUES(tags),
       category=VALUES(category)`,
    {
      ...row,
      deal_id: row.deal_id != null && row.deal_id !== '' ? String(row.deal_id) : null,
    },
  );
}

/**
 * @param {object} row
 * @returns {Promise<void>}
 */
export async function insertMessage(row) {
  if (!getPool()) return;
  await query(
    `INSERT INTO ol_bot_messages
      (chat_id, dialog_id, role, body, response_sec, credits, meta_json, created_at)
     VALUES
      (:chat_id, :dialog_id, :role, :body, :response_sec, :credits, :meta_json, :created_at)`,
    row,
  );
}

/**
 * Reload in-memory dialog state from ol_bot_* after process restart.
 *
 * @param {string} dialogId
 * @param {string|number|null|undefined} chatId
 * @param {import('./state.js').DialogState} state
 * @returns {Promise<boolean>}
 */
export async function restoreDialogFromDb(dialogId, chatId, state) {
  if (!getPool() || !state || state.extras?.restoredFromDb) return false;
  state.extras = state.extras || {};
  state.extras.restoredFromDb = true;

  const dialogKey = String(dialogId || '');
  const chatKey = chatId != null && chatId !== ''
    ? String(chatId)
    : dialogKey.replace(/^chat/i, '');
  if (!dialogKey && !chatKey) return false;

  try {
    const chats = await query(
      `SELECT deal_id, category, escalated, tags, credits
       FROM ol_bot_chats
       WHERE chat_id = :chat_id OR dialog_id = :dialog_id
       ORDER BY id DESC
       LIMIT 1`,
      { chat_id: chatKey, dialog_id: dialogKey },
    );
    const chat = Array.isArray(chats) ? chats[0] : null;
    if (chat?.deal_id) state.dealId = String(chat.deal_id);
    if (chat?.category) state.category = String(chat.category);
    if (chat?.tags && !state.activeScenario) state.activeScenario = String(chat.tags);
    if (Number(chat?.escalated) === 1) state.handedOff = true;

    const rows = await query(
      `SELECT role, body
       FROM ol_bot_messages
       WHERE chat_id = :chat_id OR dialog_id = :dialog_id
       ORDER BY id ASC
       LIMIT 60`,
      { chat_id: chatKey, dialog_id: dialogKey },
    );
    if (!Array.isArray(rows) || !rows.length) return Boolean(chat);

    if (!state.transcript.length) {
      for (const row of rows) {
        const body = String(row.body || '').trim();
        if (!body) continue;
        const role = /^(assistant|bot)$/i.test(String(row.role || '')) ? 'Bot' : 'User';
        state.transcript.push(`${role}: ${body}`);
      }
      if (state.transcript.length > 40) {
        state.transcript.splice(0, state.transcript.length - 40);
      }
    }

    for (const line of state.transcript) {
      const urls = String(line).match(/https?:\/\/[^\s)\]]+/g) || [];
      for (const url of urls) {
        if (!state.shownLinks.includes(url)) state.shownLinks.push(url);
      }
    }

    state.introduced = true;
    state.statusConnected = true;
    return true;
  } catch {
    return false;
  }
}

/**
 * @param {string} reason
 * @returns {'unknown'|'user_requested'|'rule'|null}
 */
export function bucketEscalateReason(reason) {
  const t = String(reason || '').toLowerCase();
  if (!t) return null;
  if (/(operator|human|manager|человек|оператор|менеджер)/i.test(t) && /(ask|request|хочу|позов|need|speak)/i.test(t)) {
    return 'user_requested';
  }
  if (/(rule|terminal|scenario|non_target|short_term|sharing|postponed|no_information|policy)/i.test(t)) {
    return 'rule';
  }
  if (/(know|answer|не знаю|no answer|kb|knowledge)/i.test(t)) return 'unknown';
  return 'rule';
}
