import olBotConfig from '../config/ol-bot.js';
import { canReplaceCategory } from './categories.js';
import { apartmentTypeFromRooms, roomsFromCatalogType } from './types.js';
import {
  classifyBudgetTarget,
  extractDaysToCheckin,
  extractSearchArgs,
  parseIsoDate,
} from './searchApartment.js';

/**
 * One route table: scenario ↔ CRM category (null = scenario-only, e.g. urgent).
 *
 * @type {Record<string, { scenario: string, category: string|null }>}
 */
export const ROUTES = {
  ejari: { scenario: 'ejari', category: 'ejari' },
  non_target: { scenario: 'non_target', category: 'non_target' },
  out_of_budget: { scenario: 'out_of_budget', category: 'out_of_budget' },
  postponed: { scenario: 'postponed', category: 'postponed' },
  sharing: { scenario: 'sharing', category: 'sharing' },
  short_term: { scenario: 'short_term', category: 'short_term' },
  urgent_move_in: { scenario: 'urgent_move_in', category: null },
};

export const SCENARIO_NAMES = Object.keys(ROUTES);

/**
 * @param {string|null|undefined} name
 * @returns {boolean}
 */
export function isScenarioName(name) {
  return SCENARIO_NAMES.includes(String(name || ''));
}

/**
 * @param {string|null|undefined} category
 * @returns {string|null}
 */
export function scenarioForCategory(category) {
  const key = String(category || '');
  const hit = Object.values(ROUTES).find((r) => r.category === key);
  return hit ? hit.scenario : null;
}

/**
 * @param {string|null|undefined} scenario
 * @returns {string|null}
 */
export function categoryForScenario(scenario) {
  const hit = ROUTES[String(scenario || '')];
  return hit?.category || null;
}

/**
 * @param {import('./state.js').DialogState} state
 * @param {string|null} scenario
 * @returns {{ ok: boolean, activeScenario: string|null }}
 */
export function activateScenario(state, scenario) {
  if (!scenario || scenario === 'main') {
    state.activeScenario = null;
    return { ok: true, activeScenario: null };
  }
  if (!isScenarioName(scenario)) {
    return { ok: false, activeScenario: state.activeScenario };
  }
  state.activeScenario = scenario;
  if (scenario === 'sharing' && !state.apartmentType) {
    state.apartmentType = 'room';
  }
  if (scenario === 'non_target' && !state.category) {
    state.category = 'non_target';
  }
  if (scenario === 'short_term' && !state.category) {
    state.category = 'short_term';
  }
  return { ok: true, activeScenario: scenario };
}

export { apartmentTypeFromRooms, roomsFromCatalogType };

/**
 * Active sharing scenario/category (not merely apartmentType=room).
 *
 * @param {import('./state.js').DialogState} state
 * @returns {boolean}
 */
export function isSharingRoute(state) {
  return state.category === 'sharing' || state.activeScenario === 'sharing';
}

/**
 * Sharing lead for catalog guards (route or explicit room).
 *
 * @param {import('./state.js').DialogState} state
 * @returns {boolean}
 */
export function isSharingLead(state) {
  return isSharingRoute(state) || state.apartmentType === 'room';
}

/**
 * Block apartment catalog tools for sharing/room leads.
 *
 * @param {import('./state.js').DialogState} state
 * @returns {false|{ error: string, agentInstruction: string }}
 */
export function blockApartmentCatalogTools(state) {
  if (!isSharingLead(state)) return false;
  return {
    error: 'sharing_no_catalog',
    agentInstruction:
      'Sharing/room lead: catalog has apartments only. Do not quote room prices. Collect missing params, qualify_lead(sharing), terminal A — no search/get_min_price.',
  };
}

/**
 * @param {import('./state.js').DialogState} state
 * @returns {string|null}
 */
export function resolveApartmentType(state) {
  // Room wins only on an active sharing route (stale types must not override).
  if (isSharingRoute(state) && state.apartmentType === 'room') return 'room';
  if (Array.isArray(state.types) && state.types.length) {
    return apartmentTypeFromRooms(state.types[0]);
  }
  if (state.apartmentType) return String(state.apartmentType);
  if (isSharingRoute(state)) return 'room';
  return null;
}

/**
 * Move-in on/after config.ol-bot postponedFromDate.
 *
 * @param {string|null|undefined} ddMmYyyy
 * @returns {boolean}
 */
export function isPostponedMoveIn(ddMmYyyy) {
  const move = parseIsoDate(ddMmYyyy);
  if (!move) return false;
  const from = parseIsoDate(olBotConfig.postponedFromDate);
  if (!from) return false;
  return move.getTime() >= from.getTime();
}

/**
 * @param {string} text
 * @returns {boolean}
 */
export function looksLikeRoomRequest(text) {
  const t = String(text || '');
  // Whole apartments ("1 bedroom", "2br") are not sharing — even if "room" appears nearby.
  if (/\b(?:studio|[1-3]\s*-?\s*br|[1-3]\s*-?\s*bedrooms?|bedroom\s*apartment|квартир)/i.test(t)) {
    return false;
  }
  return /\b(room|bedspace|bed\s*space|partition|master[-\s]?room|shared\s*room|shared\s*accommodation|co-?living|комнат[аыу]|койк)/i.test(t);
}

/**
 * Stay duration is short-term — not "move in in N days".
 *
 * @param {string} text
 * @returns {boolean}
 */
export function looksLikeShortTerm(text) {
  const t = String(text || '');
  return (
    /\b(weekly|per\s*week|week\s*option|short[-\s]?term|посуточ|понедельн)\b/i.test(t)
    || /\bна\s+неделю\b/i.test(t)
    || /\bless\s+than\s+a\s+month\b|\bменьше\s+месяца\b|\bподмесяц/i.test(t)
    || /\b(?:for|на|сроком)\s*([1-9]|[12]\d)\s*(?:days?|дн(?:я|ей|ень)?)\b/i.test(t)
    || /\b([1-9]|[12]\d)\s*(?:days?|дн(?:я|ей|ень)?)\s*(?:rental|stay|аренд|прожив)/i.test(t)
  );
}

/**
 * @param {Date} date
 * @returns {string}
 */
function formatDdMmYyyy(date) {
  const dd = String(date.getDate()).padStart(2, '0');
  const mm = String(date.getMonth() + 1).padStart(2, '0');
  const yyyy = date.getFullYear();
  return `${dd}-${mm}-${yyyy}`;
}

/**
 * @param {number|null|undefined} period
 * @returns {string|null}
 */
export function stayDurationFromPeriod(period) {
  const p = Number(period);
  if (!Number.isFinite(p) || p <= 0) return null;
  if (p <= 0.5) return '1-14_days';
  if (p < 1) return '15-29_days';
  if (p < 2) return '1_month';
  if (p <= 3) return '2-3_months';
  if (p <= 6) return '4-6_months';
  if (p <= 8) return '7-8_months';
  return '9-12_months';
}

/**
 * @param {number|null|undefined} days
 * @returns {string|null}
 */
export function planningFromDays(days) {
  if (days == null || !Number.isFinite(Number(days))) return null;
  const n = Number(days);
  if (n <= 1) return 'urgent';
  if (n <= 14) return 'within_two_weeks';
  if (n <= 31) return 'during_this_month';
  return 'in_a_month_or_more';
}

/**
 * Enrich CRM leave fields from known dialog state before snapshot write.
 *
 * @param {import('./state.js').DialogState} state
 * @returns {void}
 */
export function enrichStateForLeave(state) {
  const resolvedType = resolveApartmentType(state);
  if (resolvedType) state.apartmentType = resolvedType;
  if (state.period != null) {
    const derived = stayDurationFromPeriod(state.period);
    if (derived) state.stayDuration = derived;
  }
  if (state.extras?.days_to_checkin != null) {
    state.planningToMove = planningFromDays(state.extras.days_to_checkin);
  }
  if (!state.savedMoveInDate && state.routingMoveInDate && /^\d{2}-\d{2}-\d{4}$/.test(state.routingMoveInDate)) {
    state.savedMoveInDate = state.routingMoveInDate;
  }
  if (!state.category) {
    state.category = categoryForScenario(state.activeScenario) || 'no_information';
  }
  state.extras.leaveSnapshot = {
    budget: state.budget,
    residents: state.residents,
    stay_duration: state.stayDuration,
    apartment_type: state.apartmentType,
    planning_to_move: state.planningToMove,
    category: state.category,
    move_in: state.savedMoveInDate || state.routingMoveInDate || null,
  };
}

/**
 * Re-scan transcript user lines into state (before leave).
 *
 * @param {import('./state.js').DialogState} state
 * @returns {void}
 */
export function hydrateStateFromTranscript(state) {
  const lines = Array.isArray(state.transcript) ? state.transcript : [];
  for (const line of lines) {
    if (!String(line).startsWith('User: ')) continue;
    const body = String(line).slice(6);
    if (isRobotOnlyMessage(body)) continue;
    hydrateStateFromText(state, body);
  }
}

/**
 * Fill dialog state from the latest user utterance and route scenario when obvious.
 *
 * @param {import('./state.js').DialogState} state
 * @param {string} text
 * @returns {void}
 */
export function hydrateStateFromText(state, text) {
  const args = extractSearchArgs(text);
  if (args.price != null && args.price > 0) state.budget = args.price;
  if (args.period != null && args.period > 0) state.period = args.period;
  if (Array.isArray(args.types) && args.types.length) {
    state.types = args.types.map(Number);
  }
  if (Array.isArray(args.area) && args.area.length) {
    state.area = args.area.map(String);
  }
  if (args.guests != null && !state.residents) {
    if (args.guests <= 1) state.residents = 'alone';
    else if (args.guests === 2) state.residents = 'couple';
    else state.residents = 'friends';
  }
  if (!state.residents) {
    if (/\b(myself|alone|1\s*person|one\s*person|один|одна|solo)\b/i.test(text)) state.residents = 'alone';
    else if (/\b(couple|двое|пара|for\s*two|2\s*people)\b/i.test(text)) state.residents = 'couple';
    else if (/\b(family|семь)\b/i.test(text)) state.residents = 'family';
    else if (/\b(friends|друзья)\b/i.test(text)) state.residents = 'friends';
  }
  if (args.days_to_checkin != null) {
    state.extras.days_to_checkin = args.days_to_checkin;
    state.planningToMove = planningFromDays(args.days_to_checkin);
    const d = new Date();
    d.setHours(0, 0, 0, 0);
    d.setDate(d.getDate() + Number(args.days_to_checkin));
    state.routingMoveInDate = formatDdMmYyyy(d);
    state.savedMoveInDate = state.routingMoveInDate;
  }
  if (looksLikeRoomRequest(text)) {
    state.apartmentType = 'room';
    state.types = [];
  } else if (Array.isArray(args.types) && args.types.length) {
    state.apartmentType = apartmentTypeFromRooms(args.types[0]);
    // Whole apartment after sharing: leave the sharing route.
    if (
      state.activeScenario === 'sharing'
      && state.apartmentType
      && state.apartmentType !== 'room'
    ) {
      activateScenario(state, null);
      if (state.category === 'sharing') state.category = null;
    }
  }

  if (looksLikeShortTerm(text) && (state.period == null || state.period >= 1)) {
    state.period = 0.5;
  }

  if (!state.activeScenario) {
    if (looksLikeRoomRequest(text)) {
      activateScenario(state, 'sharing');
    } else if (looksLikeShortTerm(text)) {
      activateScenario(state, 'short_term');
    } else if (args.days_to_checkin != null && args.days_to_checkin <= 1) {
      activateScenario(state, 'urgent_move_in');
    } else if (isPostponedMoveIn(state.routingMoveInDate)) {
      activateScenario(state, 'postponed');
    }
  }

  // Budget below catalog min → out_of_budget (overrides urgent when both apply).
  if (
    Number(state.budget) > 0
    && state.extras.days_to_checkin != null
    && !['sharing', 'short_term', 'ejari', 'non_target', 'postponed'].includes(state.activeScenario || '')
  ) {
    const classified = classifyBudgetTarget({
      price: Number(state.budget),
      types: state.types,
      area: state.area,
      period: state.period,
      days_to_checkin: state.extras.days_to_checkin,
      move_in_date: state.routingMoveInDate,
      exclude_ids: state.excludeIds,
    });
    if (
      classified.category === 'out_of_budget'
      && canReplaceCategory(state.category, 'out_of_budget')
    ) {
      activateScenario(state, 'out_of_budget');
      state.category = 'out_of_budget';
    }
  }
}

/**
 * @param {string} text
 * @returns {boolean}
 */
export function looksLikeRobotTemplate(text) {
  const t = String(text || '');
  return (
    /отправлено роботом|sent by a robot/i.test(t) ||
    /we have received your apartment request/i.test(t) ||
    /заявк[аи].*актуальн/i.test(t) ||
    /подтвердите актуальность/i.test(t) ||
    /please confirm us if we should keep your request/i.test(t) ||
    /Colife Dubai manager[\s\S]{0,200}(unit|request|заявк)/i.test(t)
  );
}

/**
 * @param {string} text
 * @returns {string|null}
 */
export function extractUnitNumber(text) {
  const t = String(text || '');
  const m =
    t.match(/\b(?:unit|юнит)\s*[#:]?\s*(\d+(?:[./-]\d+[a-zA-Z]?)*)\b/i)
    || t.match(/\b(\d{1,4}\.\d{1,3})\b/);
  return m ? m[1] : null;
}

/**
 * Free client text after stripping robot-template boilerplate.
 *
 * @param {string} text
 * @returns {string}
 */
export function freeClientText(text) {
  return String(text || '')
    .replace(/\[B\]Отправлено роботом\[\/B\]/gi, '')
    .replace(/Отправлено роботом/gi, '')
    .replace(/Sent by a robot/gi, '')
    .replace(/\|?\s*\[(?:Yes|No(?:,\s*thanks)?|Да|Нет)\]\s*/gi, ' ')
    .replace(/Hello,[\s\S]*?continue processing it\.?/gi, ' ')
    .replace(/Здравствуйте![\s\S]*?статус\.?/gi, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/**
 * Robot template only (no separate free client reply in the same message).
 *
 * @param {string} text
 * @returns {boolean}
 */
export function isRobotOnlyMessage(text) {
  if (!looksLikeRobotTemplate(text)) return false;
  const rest = freeClientText(text);
  if (!rest) return true;
  if (/^(yes|no|no[, ]?thanks|да|нет)$/i.test(rest)) return false;
  if (extractUnitNumber(rest) && rest.length < 40) return true;
  return rest.length < 8;
}

/**
 * Text used for reply-language detection (ignore robot RU wrappers).
 *
 * @param {string} text
 * @returns {string|null} null → keep previous language
 */
export function languageSourceText(text) {
  if (!looksLikeRobotTemplate(text)) return String(text || '');
  const rest = freeClientText(text);
  if (!rest) return null;
  return rest;
}

/**
 * Detect model chain-of-thought leaked as client text.
 *
 * @param {string} text
 * @returns {boolean}
 */
export function looksLikeReasoningLeak(text) {
  const t = String(text || '').trim();
  if (!t) return false;
  // Hard markers — any length.
  if (/^\[?\s*end of turn\s*\]?$/i.test(t)) return true;
  if (/dialog state json|tool_call|function call|unified decision cycle/i.test(t)) return true;
  if (/exact next step\s*:/i.test(t)) return true;
  // Soft heuristics only on long dumps (avoid eating short client replies).
  if (t.length < 120) return false;
  return (
    /according to the|following the `|robot_message`|step\s*[123]\s*:/i.test(t) ||
    /identify (?:boundaries|template)|extract unit number|verify unit|handle free text/i.test(t) ||
    /as per the (?:skill|protocol|algorithm)/i.test(t) ||
    /^the input is (?:a |recognized as )/i.test(t) ||
    /original (?:late )?move-in date\s*:/i.test(t) ||
    /^summary\s*:\s*-\s*original/i.test(t)
  );
}

/**
 * Strip model meta markers and normalize client-facing Bitrix message text.
 *
 * @param {string} text
 * @returns {string}
 */
export function sanitizeClientText(text) {
  let out = String(text || '')
    .replace(/\[?\s*End of Turn\s*\]?/gi, ' ')
    .replace(/\r\n?/g, '\n')
    .replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '[url=$2]$1[/url]')
    .replace(/<br\s*\/?>/gi, '\n');

  out = out.replace(/[^\S\n]+/g, ' ');
  out = out.replace(/ *\n */g, '\n');
  out = out.replace(/([^\n])\s+(\d{1,2})\.\s+(?=\[url=|[A-Za-zА-Яа-яЁё«"0-9])/g, '$1\n$2. ');
  if (/\n\d+\.\s/.test(out)) {
    out = out.replace(
      /([.!?])\s+(?=(?:Рассматриваете|Хотите|Могу|Может|Would you|Do you|Are you|Shall I|Should I|Could you))/i,
      '$1\n\n',
    );
  }
  out = out.replace(/\n{3,}/g, '\n\n');
  return out.trim();
}

/**
 * Format outbound text; drop reasoning leaks. No soft substitutes.
 *
 * @param {string} text
 * @returns {string}
 */
export function prepareClientAnswer(text) {
  const out = sanitizeClientText(text);
  if (!out || looksLikeReasoningLeak(out)) return '';
  return out;
}
