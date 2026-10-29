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
 * @returns {{ answer: string, handoff: boolean }}
 */
export function parseModelReply(content) {
  const raw = String(content || '').trim();
  if (!raw) return { answer: '', handoff: true };
  try {
    const start = raw.indexOf('{');
    const end = raw.lastIndexOf('}');
    if (start >= 0 && end > start) {
      const parsed = JSON.parse(raw.slice(start, end + 1));
      const answer = String(parsed.answer || parsed.message || '').trim();
      const handoff = Boolean(parsed.handoff ?? parsed.escalate ?? parsed.needOperator);
      if (answer || handoff) return { answer, handoff };
    }
  } catch {
    // plain text fallback
  }
  if (/^HANDOFF\b/i.test(raw) || raw === 'HANDOFF') {
    return { answer: '', handoff: true };
  }
  return { answer: raw, handoff: false };
}

/**
 * @param {string} knowledgeBase
 * @param {string} question
 * @param {{ introduce?: boolean }} [options]
 * @returns {string}
 */
export function buildSystemPrompt(knowledgeBase, question, options = {}) {
  const context = knowledgeBase;
  const introduce = Boolean(options.introduce);
  return [
    'You are a Colife Dubai rental operator in Open Channels (speak as a live Colife operator, not as an AI/bot).',
    'Answer ONLY using the knowledge base below. Do not invent prices, availability, addresses, or policies.',
    'Always reply in the same language as the customer message (English → English, Russian → Russian).',
    'Never reply with empty greetings like "Hello, how can I help?" / "Привет! Чем могу помочь?". The client already wants something — go straight to useful questions or answers.',
    introduce
      ? 'This is the FIRST reply: start once with "Здравствуйте! Я оператор Colife." / "Hi! I\'m a Colife operator.", then continue. Never introduce again after that.'
      : 'Do NOT introduce yourself again. No "Здравствуйте! Я оператор Colife." — continue the dialog naturally.',
    'Read "Known lead filters" in the knowledge base. NEVER re-ask budget, check-in, period, guests, type, or area if already listed there.',
    'Collect only MISSING filters, order: 1) type (studio/1br/2br), 2) area (or any), 3) budget, 4) check-in. Ask 1–2 questions max.',
    'Clients write casually with typos (дерхам/дирхам, иду/ищу, бз/без разницы). Understand meaning; do not correct them pedantically.',
    'Budget ranges like "7-9 000" → upper value is max budget. Date ranges like "с 8-15 декабря" → earliest date is check-in.',
    'Stay "на месяц / может и дольше" → ~1 month flexible. "на двоих" without type → ask studio vs 1br only.',
    'If Live catalog search results are present, recommend those apartments with links NOW — do not ask for filters that are already known.',
    'If the knowledge base has no answer, set handoff=true and keep answer short: that you are connecting a specialist.',
    'If the customer asks for a human/operator, set handoff=true.',
    'If the customer resists budget increase, use the Colife advantages script from the knowledge base (soft, polite).',
    'If the customer rejects sharing/room/master room, briefly explain sharing advantages from the knowledge base in one message.',
    'For Ejari questions, use the Ejari detailed guide. For areas, use the Dubai districts list.',
    'If Budget classification is present, follow its agentInstruction for lead category (target/supertarget/out_of_budget); do not invent categories.',
    'supertarget = check-in within 30 days; target = check-in within 60 days with matching budget; out_of_budget = no catalog match for stated budget.',
    'If a match has comment, briefly explain that compromise to the client.',
    'If search result is not_found and agentInstruction is present, follow agentInstruction.',
    'If search result is not_found without options, hand off to operator.',
    'Return STRICT JSON only: {"answer":"string","handoff":boolean}',
    '',
    'Knowledge base:',
    context,
    '',
    `Customer question: ${question}`,
  ].join('\n');
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
