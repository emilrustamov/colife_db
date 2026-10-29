import { pickRelevantContext, loadKnowledgeBase } from './kb.js';
import { refreshCatalogKb } from './refresh.js';
import {
  classifyBudgetTarget,
  extractSearchArgs,
  formatClassifyForPrompt,
  formatKnownFiltersForPrompt,
  formatMatchesForPrompt,
  looksLikeApartmentSearch,
  looksLikeShowOptions,
  searchApartments,
  shouldClassifyBudgetTarget,
} from './searchApartment.js';
import {
  buildSystemPrompt,
  detectLang,
  FALLBACK_TEXT,
  OPERATOR_KEYBOARD,
  parseModelReply,
  shouldHandleMessageEvent,
  wantsOperator,
} from './reply.js';
import {
  chatCompletions,
  getEvents,
  handoffToOperator,
  sendMessage,
} from './vibe.js';

/**
 * @typedef {object} BotConfig
 * @property {string} apiKey
 * @property {number|string} botId
 * @property {string} [model]
 * @property {string} [knowledgeBase]
 * @property {number} [pollMs]
 * @property {number} [catalogRefreshMs]
 */

/**
 * @param {BotConfig} config
 */
export function createBotWorker(config) {
  const apiKey = config.apiKey;
  const botId = config.botId;
  const model = config.model || 'bitrix/bitrixgpt-5.5';
  let knowledgeBase = config.knowledgeBase || loadKnowledgeBase();
  const pollMs = config.pollMs || 3000;
  const catalogRefreshMs = config.catalogRefreshMs || 60 * 60 * 1000;
  let offset;
  let stopped = false;
  let busy = false;
  let lastCatalogRefresh = null;
  const handedOff = new Set();
  /** @type {Set<string>} */
  const processedMessageIds = new Set();
  /** @type {Map<string, number>} */
  const recentDialogTextAt = new Map();
  /** @type {Map<string, number[]>} */
  const shownApartmentIds = new Map();
  /** @type {Map<string, object>} */
  const lastSearchArgs = new Map();
  /** @type {Map<string, { category?: string, classified?: boolean, introduced?: boolean }>} */
  const leadQualify = new Map();
  const log = [];

  /**
   * @param {object} entry
   */
  function pushLog(entry) {
    log.push({ ...entry, at: new Date().toISOString() });
    if (log.length > 200) log.shift();
  }

  /**
   * @param {object} event
   */
  /**
   * @param {string} key
   * @returns {boolean} true if already seen
   */
  function markProcessed(key) {
    if (!key) return false;
    if (processedMessageIds.has(key)) return true;
    processedMessageIds.add(key);
    if (processedMessageIds.size > 2000) {
      const first = processedMessageIds.values().next().value;
      processedMessageIds.delete(first);
    }
    return false;
  }

  async function handleMessage(event) {
    const data = event.data || {};
    const message = data.message || {};
    const dialogId = data.chat?.dialogId;
    const chatId = data.chat?.id;
    const text = String(message.text || '').trim();
    if (!dialogId) return;

    const messageId = message.id ?? message.messageId ?? event.eventId;
    const dedupeKey = messageId != null
      ? `m:${messageId}`
      : `d:${dialogId}:${text}:${message.date || ''}`;
    if (markProcessed(dedupeKey)) {
      pushLog({
        question: text,
        answer: '',
        handedOff: false,
        error: 'duplicate_skipped',
        dialogId,
      });
      return;
    }

    const textKey = `${dialogId}::${text.toLowerCase()}`;
    const now = Date.now();
    const prevAt = recentDialogTextAt.get(textKey) || 0;
    if (text && now - prevAt < 15000) {
      pushLog({
        question: text,
        answer: '',
        handedOff: false,
        error: 'duplicate_text_skipped',
        dialogId,
      });
      return;
    }
    if (text) recentDialogTextAt.set(textKey, now);

    if (handedOff.has(String(dialogId))) {
      return;
    }

    if (wantsOperator(text)) {
      await escalate(dialogId, chatId, text, detectLang(text));
      return;
    }

    let answer = '';
    let handoff = false;
    try {
      let context = pickRelevantContext(knowledgeBase, text);
      const dialogKey = String(dialogId);
      const extracted = extractSearchArgs(text);
      const prev = lastSearchArgs.get(dialogKey) || {};
      const args = {
        area: extracted.area?.length ? extracted.area : prev.area || [],
        types: extracted.types?.length ? extracted.types : prev.types || [],
        ...(extracted.price != null
          ? { price: extracted.price }
          : prev.price != null
            ? { price: prev.price }
            : {}),
        ...(extracted.period != null
          ? { period: extracted.period }
          : prev.period != null
            ? { period: prev.period }
            : {}),
        ...(extracted.days_to_checkin != null
          ? { days_to_checkin: extracted.days_to_checkin }
          : prev.days_to_checkin != null
            ? { days_to_checkin: prev.days_to_checkin }
            : {}),
        ...(extracted.guests != null
          ? { guests: extracted.guests }
          : prev.guests != null
            ? { guests: prev.guests }
            : {}),
        areaFlexible: Boolean(extracted.areaFlexible || prev.areaFlexible),
        exclude_ids: shownApartmentIds.get(dialogKey) || [],
      };
      lastSearchArgs.set(dialogKey, args);
      context = `${context}\n\n${formatKnownFiltersForPrompt(args)}`;

      const qualify = leadQualify.get(dialogKey) || {};
      const hasShown = (shownApartmentIds.get(dialogKey) || []).length > 0;
      const showOptions = looksLikeShowOptions(text);
      const hasType = (args.types || []).length > 0;
      const hasBudget = args.price != null && args.price > 0;
      const hasArea = args.areaFlexible || (args.area || []).length > 0;
      const readyToSearch = hasType && hasBudget && hasArea;
      const collectingBeforeSearch = !hasShown && !showOptions && !readyToSearch;
      if (shouldClassifyBudgetTarget(args, Boolean(qualify.classified)) && collectingBeforeSearch) {
        const classified = classifyBudgetTarget(args);
        leadQualify.set(dialogKey, {
          ...qualify,
          classified: true,
          category: classified.category,
        });
        context = `${context}\n\n${formatClassifyForPrompt(classified)}`;
      }

      const wantsCatalogSearch =
        showOptions || hasShown || readyToSearch;
      if (wantsCatalogSearch) {
        const search = searchApartments(args);
        const matches = (search.apartments || []).map((row) => row.apartment);
        if (matches.length) {
          const exclude = shownApartmentIds.get(dialogKey) || [];
          for (const item of matches) {
            const id = Number(item.apartmentId);
            if (Number.isFinite(id) && !exclude.includes(id)) exclude.push(id);
          }
          shownApartmentIds.set(dialogKey, exclude);
        }
        context = `${context}\n\n### Live catalog search\n${JSON.stringify(args)}\n${formatMatchesForPrompt(matches, search.agentInstruction || '')}`;
      }
      const introduce = !qualify.introduced;
      const completion = await chatCompletions(apiKey, {
        model,
        temperature: 0.2,
        messages: [
          { role: 'system', content: buildSystemPrompt(context, text, { introduce }) },
          { role: 'user', content: text },
        ],
      });
      const content = completion?.choices?.[0]?.message?.content || '';
      ({ answer, handoff } = parseModelReply(content));
      if (answer) {
        leadQualify.set(dialogKey, {
          ...(leadQualify.get(dialogKey) || qualify),
          introduced: true,
        });
      }
    } catch (err) {
      const lang = detectLang(text);
      answer = FALLBACK_TEXT[lang];
      handoff = true;
      pushLog({
        question: text,
        answer,
        handedOff: true,
        error: err.message,
        dialogId,
      });
    }

    if (!answer && handoff) {
      const lang = detectLang(text);
      answer = lang === 'ru'
        ? 'Подключаю специалиста Colife.'
        : 'Connecting a Colife specialist.';
    }

    if (answer) {
      await sendMessage(apiKey, botId, dialogId, {
        message: answer,
        keyboard: OPERATOR_KEYBOARD,
      });
    }

    pushLog({
      question: text,
      answer,
      handedOff: handoff,
      dialogId,
    });

    if (handoff) {
      await escalate(dialogId, chatId, text, detectLang(text), false);
    }
  }

  /**
   * @param {string} dialogId
   * @param {number|string} chatId
   * @param {string} question
   * @param {'en'|'ru'} lang
   * @param {boolean} [notify]
   */
  async function escalate(dialogId, chatId, question, lang, notify = true) {
    if (handedOff.has(String(dialogId))) return;
    handedOff.add(String(dialogId));
    shownApartmentIds.delete(String(dialogId));
    lastSearchArgs.delete(String(dialogId));
    leadQualify.delete(String(dialogId));
    if (notify) {
      const msg = lang === 'ru'
        ? 'Подключаю специалиста. Пожалуйста, подождите.'
        : 'Connecting a specialist. Please wait.';
      try {
        await sendMessage(apiKey, botId, dialogId, { message: msg });
      } catch {
        // ignore notify failure
      }
    }
    if (chatId != null) {
      try {
        await handoffToOperator(apiKey, botId, chatId);
      } catch (err) {
        pushLog({
          question,
          answer: '',
          handedOff: true,
          error: `handoff: ${err.message}`,
          dialogId,
        });
      }
    }
  }

  /**
   * @returns {Promise<void>}
   */
  async function pollOnce() {
    if (busy || stopped) return;
    busy = true;
    try {
      let hasMore = true;
      while (hasMore) {
        const query = { limit: 50 };
        if (offset != null) query.offset = offset;
        let body;
        try {
          body = await getEvents(apiKey, botId, query);
        } catch (err) {
          if (err.code === 'BOT_EVENTS_BUSY' || err.status === 409) {
            return;
          }
          throw err;
        }
        const events = body?.data?.events || [];
        for (const event of events) {
          if (shouldHandleMessageEvent(event)) {
            await handleMessage(event);
          }
        }
        if (body?.data?.nextOffset != null) {
          offset = body.data.nextOffset;
        }
        hasMore = Boolean(body?.data?.hasMore);
      }
    } catch (err) {
      pushLog({ question: '', answer: '', handedOff: false, error: err.message });
    } finally {
      busy = false;
    }
  }

  /**
   * @returns {Promise<object>}
   */
  async function refreshCatalog() {
    const result = await refreshCatalogKb();
    knowledgeBase = loadKnowledgeBase();
    lastCatalogRefresh = {
      ...result,
      kbChars: knowledgeBase.length,
    };
    pushLog({
      question: '',
      answer: result.ok ? `catalog refreshed (${result.chars} chars)` : '',
      handedOff: false,
      error: result.ok ? undefined : result.error,
      dialogId: 'catalog-refresh',
    });
    return lastCatalogRefresh;
  }

  /**
   * @returns {{ stop: Function, getStatus: Function, refreshCatalog: Function }}
   */
  function start() {
    stopped = false;
    const timer = setInterval(() => {
      pollOnce();
    }, pollMs);
    const catalogTimer = setInterval(() => {
      refreshCatalog().catch(() => {});
    }, catalogRefreshMs);
    pollOnce();
    refreshCatalog().catch(() => {});
    return {
      stop() {
        stopped = true;
        clearInterval(timer);
        clearInterval(catalogTimer);
      },
      getStatus() {
        return {
          botId,
          offset: offset ?? null,
          handedOff: handedOff.size,
          recentLog: log.slice(-20),
          kbChars: knowledgeBase.length,
          lastCatalogRefresh,
          catalogRefreshMs,
        };
      },
      reloadKb() {
        knowledgeBase = loadKnowledgeBase();
        return knowledgeBase.length;
      },
      refreshCatalog,
    };
  }

  return { start, pollOnce, shouldHandleMessageEvent };
}
