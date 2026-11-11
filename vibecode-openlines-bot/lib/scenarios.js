import { extractDaysToCheckin, extractSearchArgs } from './searchApartment.js';

export const SCENARIO_NAMES = [
  'ejari',
  'non_target',
  'out_of_budget',
  'postponed',
  'sharing',
  'short_term',
  'urgent_move_in',
];

const CATEGORY_TO_SCENARIO = {
  ejari: 'ejari',
  non_target: 'non_target',
  out_of_budget: 'out_of_budget',
  postponed: 'postponed',
  sharing: 'sharing',
  short_term: 'short_term',
};

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
  return CATEGORY_TO_SCENARIO[key] || null;
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
  return { ok: true, activeScenario: scenario };
}

/**
 * @param {number|null|undefined} type
 * @returns {string|null}
 */
export function apartmentTypeFromRooms(type) {
  switch (Number(type)) {
    case 0:
      return 'studio';
    case 1:
      return '1_bedroom';
    case 2:
      return '2_bedroom';
    case 3:
      return '3_bedroom';
    default:
      return null;
  }
}

/**
 * @param {import('./state.js').DialogState} state
 * @returns {string|null}
 */
export function resolveApartmentType(state) {
  if (state.apartmentType) return String(state.apartmentType);
  if (state.category === 'sharing' || state.activeScenario === 'sharing') return 'room';
  if (Array.isArray(state.types) && state.types.length) {
    return apartmentTypeFromRooms(state.types[0]);
  }
  return null;
}

/**
 * @param {string} text
 * @returns {boolean}
 */
export function looksLikeRoomRequest(text) {
  return /\b(room|bedspace|bed\s*space|partition|shared\s*room|shared\s*accommodation|co-?living|комнат[аыу]|койк)/i.test(
    String(text || ''),
  ) && !/\b(studio|1\s*-?\s*br|2\s*-?\s*br|bedroom\s*apartment|квартир)/i.test(String(text || ''));
}

/**
 * @param {string} text
 * @returns {boolean}
 */
export function looksLikeShortTerm(text) {
  const t = String(text || '');
  return (
    /\b(weekly|per\s*week|week\s*option|short[-\s]?term|посуточ|понедельн|на\s*недел)/i.test(t)
    || /\bless\s+than\s+a\s+month\b|\bменьше\s+месяца\b|\bподмесяц/i.test(t)
    || /\b([1-9]|[12]\d)\s*days?\b/i.test(t)
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
  if (p < 0.5) return '1-14_days';
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
  if (!state.apartmentType) state.apartmentType = resolveApartmentType(state);
  if (!state.stayDuration) state.stayDuration = stayDurationFromPeriod(state.period);
  if (!state.planningToMove) {
    state.planningToMove = planningFromDays(state.extras?.days_to_checkin);
  }
  if (!state.savedMoveInDate && state.routingMoveInDate && /^\d{2}-\d{2}-\d{4}$/.test(state.routingMoveInDate)) {
    state.savedMoveInDate = state.routingMoveInDate;
  }
  if (!state.residents) {
    if (Array.isArray(state.types) && state.types.length === 0 && state.apartmentType === 'room') {
      state.residents = state.residents || null;
    }
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
 * Re-scan transcript user lines into state (before leave / empty fallback).
 *
 * @param {import('./state.js').DialogState} state
 * @returns {void}
 */
export function hydrateStateFromTranscript(state) {
  const lines = Array.isArray(state.transcript) ? state.transcript : [];
  for (const line of lines) {
    if (!String(line).startsWith('User: ')) continue;
    hydrateStateFromText(state, String(line).slice(6));
  }
  enrichStateForLeave(state);
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
  if (args.price != null && (state.budget == null || !(state.budget > 0))) {
    state.budget = args.price;
  }
  if (args.period != null && (state.period == null || !(state.period > 0))) {
    state.period = args.period;
  }
  if (Array.isArray(args.types) && args.types.length && !state.types.length) {
    state.types = args.types.map(Number);
  }
  if (Array.isArray(args.area) && args.area.length && !state.area.length) {
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
    if (!state.planningToMove) {
      state.planningToMove = planningFromDays(args.days_to_checkin);
    }
    if (!state.routingMoveInDate) {
      const d = new Date();
      d.setHours(0, 0, 0, 0);
      d.setDate(d.getDate() + Number(args.days_to_checkin));
      state.routingMoveInDate = formatDdMmYyyy(d);
      state.savedMoveInDate = state.savedMoveInDate || state.routingMoveInDate;
    }
  }
  if (looksLikeRoomRequest(text) && !state.apartmentType) {
    state.apartmentType = 'room';
  }
  if (!state.apartmentType) {
    state.apartmentType = resolveApartmentType(state);
  }
  if (looksLikeShortTerm(text) && (state.period == null || state.period >= 1)) {
    if (/\bless\s+than\s+a\s+month\b|\bweekly\b|\b\d+\s*days?\b/i.test(text)) {
      state.period = state.period != null && state.period < 1 ? state.period : 0.5;
    }
  }
  if (state.period == null && args.days_to_checkin != null && args.days_to_checkin <= 29 && /\bday/i.test(text)) {
    state.period = args.days_to_checkin / 30;
  }

  if (!state.activeScenario) {
    if (looksLikeRoomRequest(text)) {
      activateScenario(state, 'sharing');
    } else if (looksLikeShortTerm(text)) {
      activateScenario(state, 'short_term');
    } else if ((args.days_to_checkin != null && args.days_to_checkin <= 1) || extractDaysToCheckin(text) === 1) {
      activateScenario(state, 'urgent_move_in');
    }
  }
}

/**
 * Client clarify text when the model returns nothing useful.
 *
 * @param {import('./state.js').DialogState} state
 * @returns {string}
 */
export function clarifyFallback(state) {
  const ru = state.language === 'ru';
  const hasMove = Boolean(
    state.routingMoveInDate
    || state.savedMoveInDate
    || state.extras?.days_to_checkin != null
    || state.planningToMove,
  );
  const hasPeriod = state.period != null && Number(state.period) > 0;
  const hasType = Boolean(state.apartmentType || (state.types && state.types.length));
  const hasBudget = state.budget != null && Number(state.budget) > 0;
  const hasArea = Array.isArray(state.area) && state.area.length > 0;

  if (!hasMove) {
    return ru
      ? 'Уточните, пожалуйста, дату заезда — так я подберу варианты точнее.'
      : 'Could you clarify your move-in date so I can find the right options?';
  }
  if (!hasPeriod) {
    return ru
      ? 'На какой срок аренды ориентируетесь?'
      : 'How long are you planning to stay?';
  }
  if (!hasType) {
    return ru
      ? 'Какой тип жилья нужен — студия, 1 bedroom или комната?'
      : 'What apartment type are you looking for — studio, 1-bedroom, or a room?';
  }
  if (!hasBudget) {
    return ru
      ? 'Какой у вас максимальный бюджет в месяц (AED)?'
      : 'What is your maximum monthly budget in AED?';
  }
  if (!hasArea) {
    return ru
      ? 'Уточните, пожалуйста, район — продолжу подбор по уже известным условиям.'
      : 'Which area should I prioritize? I can continue with the details you already shared.';
  }
  return ru
    ? 'Продолжаю подбор по уже известным условиям. Уточните, если нужно что-то изменить.'
    : 'I can continue with the details you already shared. Tell me if anything should change.';
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
    t.match(/\bunit\s+(\d+(?:[./-]\d+[a-zA-Z]?)*)\b/i) ||
    t.match(/\b(?:юнит|unit)\s*[#:]?\s*(\d+(?:[./-]\d+[a-zA-Z]?)*)\b/i) ||
    t.match(/\b(\d{1,4}\.\d{1,3})\b/);
  return m ? m[1] : null;
}

/**
 * Robot template only (no separate free client reply in the same message).
 *
 * @param {string} text
 * @returns {boolean}
 */
export function isRobotOnlyMessage(text) {
  if (!looksLikeRobotTemplate(text)) return false;
  let rest = String(text || '')
    .replace(/\[B\]Отправлено роботом\[\/B\]/gi, '')
    .replace(/Sent by a robot/gi, '')
    .replace(/\|?\s*\[(?:Yes|No(?:,\s*thanks)?|Да|Нет)\]\s*/gi, ' ')
    .replace(/Hello,[\s\S]*?continue processing it\.?/gi, ' ')
    .replace(/Здравствуйте![\s\S]*?статус\.?/gi, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  if (!rest) return true;
  if (/^(yes|no|no[, ]?thanks|да|нет)$/i.test(rest)) return false;
  if (extractUnitNumber(rest) && rest.length < 40) return true;
  return rest.length < 8;
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
  if (/^\[?\s*end of turn\s*\]?$/i.test(t)) return true;
  return (
    /according to the|following the `|robot_message`|step\s*[123]\s*:/i.test(t) ||
    /identify (?:boundaries|template)|extract unit number|verify unit|handle free text/i.test(t) ||
    /as per the (?:skill|protocol|algorithm)/i.test(t) ||
    /dialog state json|tool_call|function call/i.test(t) ||
    /^the input is (?:a |recognized as )/i.test(t) ||
    /exact next step\s*:/i.test(t) ||
    /unified decision cycle/i.test(t) ||
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
