import { query } from './db.js';

/** @type {{ model: string|null, at: number }} */
let modelCache = { model: null, at: 0 };

/**
 * Active AI model from ol_bot_settings (falls back to env / default).
 *
 * @param {string} [fallback]
 * @returns {Promise<string>}
 */
export async function getAiModel(fallback = 'bitrix/bitrixgpt-5.5-thinking') {
  const envFallback = String(process.env.AI_MODEL || fallback || '').trim() || fallback;
  if (modelCache.model && Date.now() - modelCache.at < 5000) {
    return modelCache.model;
  }
  try {
    const rows = await query(
      'SELECT `value` FROM ol_bot_settings WHERE `key` = :key LIMIT 1',
      { key: 'ai_model' },
    );
    const value = String(rows?.[0]?.value || '').trim();
    if (value) {
      modelCache = { model: value, at: Date.now() };
      return value;
    }
  } catch {
    // DB optional at boot
  }
  modelCache = { model: envFallback, at: Date.now() };
  return envFallback;
}
