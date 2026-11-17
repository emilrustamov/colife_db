const HANDOFF_RE =
  /(^|[^\p{L}\p{N}_])(оператор|менеджер|человек|живой|сотрудник|operator|human|agent|manager|specialist)(?=[^\p{L}\p{N}_]|$)/iu;
const HANDOFF_CMD_RE = /^\s*\/+operator\s*$/i;
const HANDOFF_INTENT_RE =
  /(позов|нужен|хочу|call|need|speak|talk|connect|свяж|перевед)/i;

/**
 * @param {string} text
 * @returns {boolean}
 */
export function wantsOperator(text) {
  const t = String(text || '').trim();
  if (!t) return false;
  if (HANDOFF_CMD_RE.test(t)) return true;
  return HANDOFF_RE.test(t) && HANDOFF_INTENT_RE.test(t);
}

/**
 * @param {object} event
 * @returns {boolean}
 */
export function shouldHandleMessageEvent(event) {
  if (!event || event.type !== 'ONIMBOTV2MESSAGEADD') return false;
  const data = event.data || {};
  const message = data.message || {};
  const botId = data.bot?.id;
  if (message.isSystem) return false;
  if (botId != null && Number(message.authorId) === Number(botId)) return false;
  if (!String(message.text || '').trim() && !(message.params?.FILE_ID || []).length) return false;
  return true;
}

/**
 * @param {string} content
 * @returns {{ answer: string, handoff: boolean, scenario: string|null }}
 */
export function parseModelReply(content) {
  const raw = String(content || '').trim();
  if (!raw) return { answer: '', handoff: true, scenario: null };
  try {
    const start = raw.indexOf('{');
    const end = raw.lastIndexOf('}');
    if (start >= 0 && end > start) {
      const parsed = JSON.parse(raw.slice(start, end + 1));
      const answer = String(parsed.answer || parsed.message || '').trim();
      const handoff = Boolean(parsed.handoff ?? parsed.escalate ?? parsed.needOperator);
      const scenario = parsed.scenario != null ? String(parsed.scenario) : null;
      if (answer || handoff || scenario) return { answer, handoff, scenario };
    }
  } catch {
    // plain text
  }
  if (/^HANDOFF\b/i.test(raw) || raw === 'HANDOFF') {
    return { answer: '', handoff: true, scenario: null };
  }
  return { answer: raw, handoff: false, scenario: null };
}

export const OPERATOR_KEYBOARD = [
  {
    TEXT: 'Call an operator',
    ACTION: 'SEND',
    ACTION_VALUE: '/operator',
    BG_COLOR_TOKEN: 'primary',
  },
  {
    TEXT: 'Оператор',
    ACTION: 'SEND',
    ACTION_VALUE: '/operator',
  },
];

export const FALLBACK_TEXT = {
  en: 'I could not answer right now. Connecting you to a specialist.',
  ru: 'Сейчас не могу ответить. Подключаю специалиста.',
};

/**
 * @param {string} text
 * @returns {'en'|'ru'}
 */
export function detectLang(text) {
  return /[а-яё]/i.test(String(text || '')) ? 'ru' : 'en';
}
