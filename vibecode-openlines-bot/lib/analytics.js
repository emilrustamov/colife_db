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
