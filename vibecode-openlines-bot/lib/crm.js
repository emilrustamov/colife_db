const CATEGORY_LABEL = {
  non_target: 'Non-target',
  out_of_budget: 'Out of budget',
  supertarget: 'Supertarget',
  target: 'Target',
  postponed: 'Postponed',
  student: 'Student',
  sharing: 'Sharing',
  short_term: 'Short-term',
  ejari: 'Ejari',
  no_information: 'No information',
};

const STATUS_LABEL = {
  not_connected: 'Not connected',
  connected: 'Connected',
  disconnected: 'Disconnected',
};

const APARTMENT_LABEL = {
  bedspace: 'Bedspace',
  room: 'Room',
  studio: 'Studio',
  '1_bedroom': '1 bedroom',
  '2_bedroom': '2 bedroom',
  '3_bedroom': '3 bedroom',
};

const RESIDENTS_LABEL = {
  alone: 'Alone',
  couple: 'Couple',
  family: 'Family',
  friends: 'Friends',
};

const STAY_LABEL = {
  '1-14_days': '1-14 days',
  '15-29_days': '15-29 days',
  '1_month': '1 month',
  '2-3_months': '2-3 month',
  '4-6_months': '4-6 month',
  '7-8_months': '7-8 month',
  '9-12_months': '9-12 month',
};

const PLANNING_LABEL = {
  urgent: 'Urgent',
  within_two_weeks: 'Within two weeks',
  during_this_month: 'During this month',
  in_a_month_or_more: 'In a month or more',
};

const FIELD = {
  category: process.env.CRM_FIELD_CATEGORY || 'UF_CRM_1763647077',
  chatStatus: process.env.CRM_FIELD_CHAT_STATUS || 'UF_CRM_OL_BOT_CHAT_STATUS',
  apartmentType: process.env.CRM_FIELD_APARTMENT_TYPE || 'UF_CRM_1692861855',
  residents: process.env.CRM_FIELD_RESIDENTS || 'UF_CRM_1692862136',
  stayDuration: process.env.CRM_FIELD_STAY_DURATION || 'UF_CRM_1769420060313',
  planningToMove: process.env.CRM_FIELD_PLANNING_TO_MOVE || 'UF_CRM_1692862111',
  budget: process.env.CRM_FIELD_BUDGET || 'UF_CRM_1692861971',
  moveInDate: process.env.CRM_FIELD_MOVE_IN_DATE || 'UF_CRM_OL_BOT_MOVE_IN_DATE',
};

/** @type {Map<string, Map<string, string>>|null} */
let enumCache = null;

/**
 * @returns {boolean}
 */
export function crmEnabled() {
  return Boolean(String(process.env.CRM_WEBHOOK || '').trim())
    && String(process.env.CRM_ENABLED || '1') !== '0';
}

/**
 * @returns {string}
 */
function webhookBase() {
  return String(process.env.CRM_WEBHOOK || '').replace(/\/+$/, '');
}

/**
 * @param {string} method
 * @param {object} [params]
 * @returns {Promise<any>}
 */
export async function bitrixCall(method, params = {}) {
  const base = webhookBase();
  if (!base) throw new Error('CRM_WEBHOOK empty');
  const res = await fetch(`${base}/${method}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(params),
  });
  const body = await res.json().catch(() => ({}));
  if (body.error) {
    const err = new Error(body.error_description || body.error);
    err.code = body.error;
    err.body = body;
    throw err;
  }
  return body.result;
}

/**
 * @param {string} fieldName
 * @returns {Promise<Map<string, string>>}
 */
async function enumMap(fieldName) {
  if (!enumCache) enumCache = new Map();
  if (enumCache.has(fieldName)) return enumCache.get(fieldName);
  const list = (await bitrixCall('crm.deal.userfield.list', {})) || [];
  const field = list.find((row) => row.FIELD_NAME === fieldName);
  /** @type {Map<string, string>} */
  const map = new Map();
  for (const item of field?.LIST || []) {
    map.set(String(item.VALUE || '').trim().toLowerCase(), String(item.ID));
  }
  enumCache.set(fieldName, map);
  return map;
}

/**
 * @param {string} fieldName
 * @param {string} label
 * @returns {Promise<string|null>}
 */
async function enumId(fieldName, label) {
  if (!label) return null;
  const map = await enumMap(fieldName);
  return map.get(String(label).trim().toLowerCase()) || null;
}

/**
 * @param {number} budget
 * @returns {string}
 */
export function budgetToLabel(budget) {
  const n = Number(budget);
  if (!Number.isFinite(n) || n <= 0) return 'No information';
  if (n >= 99999) return '20000+';
  const ranges = [
    [0, 2000, '0-2000'],
    [2001, 4000, '2001-4000'],
    [4001, 6000, '4001-6000'],
    [6001, 8000, '6001-8000'],
    [8001, 10000, '8001-10000'],
    [10001, 12000, '10001-12000'],
    [12001, 14000, '12001-14000'],
    [14000, 16000, '14000-16000'],
    [16000, 20000, '16000-20000'],
  ];
  for (const [from, to, label] of ranges) {
    if (n >= from && n <= to) return label;
  }
  return '20000+';
}

/**
 * @param {string} ddMmYyyy
 * @returns {string|null}
 */
export function toBitrixDate(ddMmYyyy) {
  const m = String(ddMmYyyy || '').trim().match(/^(\d{2})-(\d{2})-(\d{4})$/);
  if (!m) return null;
  return `${m[3]}-${m[2]}-${m[1]}`;
}

/**
 * @param {string} [preferred]
 * @returns {string}
 */
function dealTitle(preferred) {
  const forced = String(process.env.CRM_DEAL_TITLE || '').trim();
  if (forced) return forced;
  const t = String(preferred || '').trim();
  return t || `OL Bot ${new Date().toISOString()}`;
}

/**
 * @param {string} [title]
 * @returns {Promise<string>}
 */
export async function createDeal(title) {
  const id = await bitrixCall('crm.deal.add', {
    fields: {
      TITLE: dealTitle(title),
      COMMENTS: 'Created by OL Bot CRM bridge',
    },
  });
  return String(id);
}

/**
 * @param {string|number} dealId
 * @returns {Promise<object>}
 */
export async function getDeal(dealId) {
  return bitrixCall('crm.deal.get', { id: dealId });
}

/**
 * @param {import('./state.js').DialogState} state
 * @param {string} [title]
 * @returns {Promise<string|null>}
 */
export async function ensureDeal(state, title) {
  if (!crmEnabled()) return state.dealId || null;
  if (state.dealId) return String(state.dealId);
  const id = await createDeal(title);
  state.dealId = id;
  return id;
}

/**
 * @param {string|number} dealId
 * @param {Record<string, any>} fields
 * @returns {Promise<object>}
 */
export async function updateDealFields(dealId, fields) {
  const clean = {};
  for (const [k, v] of Object.entries(fields || {})) {
    if (v == null || v === '') continue;
    clean[k] = v;
  }
  if (!Object.keys(clean).length) {
    return { ok: true, skipped: true, dealId: String(dealId) };
  }
  await bitrixCall('crm.deal.update', { id: dealId, fields: clean });
  return { ok: true, dealId: String(dealId), fields: clean };
}

/**
 * @param {import('./state.js').DialogState} state
 * @param {string} category
 * @returns {Promise<object>}
 */
export async function writeCategory(state, category) {
  if (!crmEnabled()) return { ok: true, stub: true };
  const dealId = await ensureDeal(state, `OL Bot qualify ${category || ''}`.trim());
  const label = CATEGORY_LABEL[category] || category;
  const id = await enumId(FIELD.category, label);
  if (!id) return { ok: false, error: `unknown_category:${category}`, dealId };
  const result = await updateDealFields(dealId, { [FIELD.category]: id });
  state.extras.lastCrmWrite = { at: new Date().toISOString(), kind: 'qualify_lead', ...result, label };
  return { ...result, stub: false, label };
}

/**
 * @param {import('./state.js').DialogState} state
 * @param {string} status
 * @returns {Promise<object>}
 */
export async function writeChatStatus(state, status) {
  if (!crmEnabled()) return { ok: true, stub: true };
  const dealId = await ensureDeal(state, `OL Bot status ${status || ''}`.trim());
  const label = STATUS_LABEL[status] || status;
  const id = await enumId(FIELD.chatStatus, label);
  if (!id) return { ok: false, error: `unknown_status:${status}`, dealId };
  const result = await updateDealFields(dealId, { [FIELD.chatStatus]: id });
  state.extras.lastCrmWrite = { at: new Date().toISOString(), kind: 'chat_status', ...result, label };
  return { ...result, stub: false, label };
}

/**
 * @param {import('./state.js').DialogState} state
 * @param {string} moveInDateDdMmYyyy
 * @returns {Promise<object>}
 */
export async function writeMoveInDate(state, moveInDateDdMmYyyy) {
  if (!crmEnabled()) return { ok: true, stub: true };
  const dealId = await ensureDeal(state, 'OL Bot move-in');
  const bitrixDate = toBitrixDate(moveInDateDdMmYyyy);
  if (!bitrixDate) return { ok: false, error: 'bad_date', dealId };
  const result = await updateDealFields(dealId, { [FIELD.moveInDate]: bitrixDate });
  state.extras.lastCrmWrite = { at: new Date().toISOString(), kind: 'move_in_date', ...result, date: bitrixDate };
  return { ...result, stub: false, date: bitrixDate };
}

/**
 * @param {import('./state.js').DialogState} state
 * @returns {Promise<object>}
 */
export async function writeLeaveSnapshot(state) {
  if (!crmEnabled()) return { ok: true, stub: true };
  const dealId = await ensureDeal(state, 'OL Bot leave');
  /** @type {Record<string, any>} */
  const fields = {};

  if (state.apartmentType) {
    const id = await enumId(FIELD.apartmentType, APARTMENT_LABEL[state.apartmentType] || state.apartmentType);
    if (id) fields[FIELD.apartmentType] = id;
  }
  if (state.residents) {
    const id = await enumId(FIELD.residents, RESIDENTS_LABEL[state.residents] || state.residents);
    if (id) fields[FIELD.residents] = id;
  }
  if (state.stayDuration) {
    const id = await enumId(FIELD.stayDuration, STAY_LABEL[state.stayDuration] || state.stayDuration);
    if (id) fields[FIELD.stayDuration] = id;
  }
  if (state.planningToMove) {
    const id = await enumId(FIELD.planningToMove, PLANNING_LABEL[state.planningToMove] || state.planningToMove);
    if (id) fields[FIELD.planningToMove] = id;
  }
  if (state.budget != null) {
    const id = await enumId(FIELD.budget, budgetToLabel(state.budget));
    if (id) fields[FIELD.budget] = id;
  }
  if (state.category) {
    const id = await enumId(FIELD.category, CATEGORY_LABEL[state.category] || state.category);
    if (id) fields[FIELD.category] = id;
  }
  if (state.savedMoveInDate) {
    const d = toBitrixDate(state.savedMoveInDate);
    if (d) fields[FIELD.moveInDate] = d;
  }

  const result = await updateDealFields(dealId, fields);
  state.extras.lastCrmWrite = { at: new Date().toISOString(), kind: 'leave_chat', ...result };
  return { ...result, stub: false };
}

/**
 * Resolve enum labels from deal field values for asserts.
 *
 * @param {object} deal
 * @returns {Promise<object>}
 */
export async function readDealOlFields(deal) {
  const out = {
    dealId: String(deal?.ID || ''),
    category: null,
    chatStatus: null,
    apartmentType: null,
    residents: null,
    stayDuration: null,
    planningToMove: null,
    budget: null,
    moveInDate: deal?.[FIELD.moveInDate] || null,
  };
  const pairs = [
    ['category', FIELD.category],
    ['chatStatus', FIELD.chatStatus],
    ['apartmentType', FIELD.apartmentType],
    ['residents', FIELD.residents],
    ['stayDuration', FIELD.stayDuration],
    ['planningToMove', FIELD.planningToMove],
    ['budget', FIELD.budget],
  ];
  for (const [key, fieldName] of pairs) {
    const raw = deal?.[fieldName];
    if (raw == null || raw === '') continue;
    const map = await enumMap(fieldName);
    for (const [label, id] of map.entries()) {
      if (String(id) === String(raw)) {
        out[key] = label;
        break;
      }
    }
  }
  return out;
}

export const CRM_FIELDS = FIELD;
