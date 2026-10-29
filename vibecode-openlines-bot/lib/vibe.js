const BASE = process.env.VIBE_API_BASE || 'https://vibecode.bitrix24.com/v1';

/**
 * @param {string} apiKey
 * @param {string} path
 * @param {object} [options]
 * @returns {Promise<any>}
 */
export async function vibeFetch(apiKey, path, options = {}) {
  const res = await fetch(`${BASE}${path}`, {
    ...options,
    headers: {
      'X-Api-Key': apiKey,
      'Content-Type': 'application/json',
      ...(options.headers || {}),
    },
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok || body.success === false) {
    const err = new Error(body?.error?.message || `HTTP ${res.status}`);
    err.status = res.status;
    err.code = body?.error?.code;
    err.body = body;
    throw err;
  }
  return body;
}

/**
 * @param {string} apiKey
 * @param {number|string} botId
 * @param {object} [query]
 */
export async function getEvents(apiKey, botId, query = {}) {
  const qs = new URLSearchParams();
  if (query.offset != null) qs.set('offset', String(query.offset));
  if (query.limit != null) qs.set('limit', String(query.limit));
  const suffix = qs.toString() ? `?${qs}` : '';
  return vibeFetch(apiKey, `/bots/${botId}/events${suffix}`);
}

/**
 * @param {string} apiKey
 * @param {number|string} botId
 * @param {string} dialogId
 * @param {object} fields
 */
export async function sendMessage(apiKey, botId, dialogId, fields) {
  return vibeFetch(apiKey, `/bots/${botId}/messages`, {
    method: 'POST',
    body: JSON.stringify({ dialogId, fields }),
  });
}

/**
 * @param {string} apiKey
 * @param {number|string} botId
 * @param {number|string} chatId
 */
export async function handoffToOperator(apiKey, botId, chatId) {
  return vibeFetch(apiKey, `/bots/${botId}/openlines/session/operator`, {
    method: 'POST',
    body: JSON.stringify({ chatId }),
  });
}

/**
 * @param {string} apiKey
 * @param {object} payload
 */
export async function chatCompletions(apiKey, payload) {
  const res = await fetch(`${BASE}/chat/completions`, {
    method: 'POST',
    headers: {
      'X-Api-Key': apiKey,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok || body.error) {
    const err = new Error(body?.error?.message || `AI HTTP ${res.status}`);
    err.status = res.status;
    err.body = body;
    throw err;
  }
  return body;
}
