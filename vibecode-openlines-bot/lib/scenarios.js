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
  return { ok: true, activeScenario: scenario };
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
 * Strip model meta markers from client-facing text.
 *
 * @param {string} text
 * @returns {string}
 */
export function sanitizeClientText(text) {
  return String(text || '')
    .replace(/\[?\s*End of Turn\s*\]?/gi, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}
