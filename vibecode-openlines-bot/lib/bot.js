import { pickRelevantContext, loadKnowledgeBase } from './kb.js';
import { refreshCatalogKb } from './refresh.js';
import { DialogStore } from './state.js';
import { buildSystemInstruction, loadToolSchemas, loadTemplate } from './templates.js';
import { executeTool } from './tools.js';
import { upsertChat, insertMessage, bucketEscalateReason, restoreDialogFromDb } from './analytics.js';
import {
  detectLang,
  FALLBACK_TEXT,
  OPERATOR_KEYBOARD,
  shouldHandleMessageEvent,
  wantsOperator,
} from './reply.js';
import {
  clarifyFallback,
  enrichStateForLeave,
  extractUnitNumber,
  hydrateStateFromText,
  hydrateStateFromTranscript,
  isRobotOnlyMessage,
  looksLikeReasoningLeak,
  looksLikeRobotTemplate,
  sanitizeClientText,
} from './scenarios.js';
import { findByUnitNumber } from './tools.js';
import {
  chatCompletions,
  getEvents,
  handoffToOperator,
  sendMessage,
} from './vibe.js';
import { crmEnabled, writeChatStatus, writeLeaveSnapshot } from './crm.js';

/**
 * @param {object} result
 * @returns {object}
 */
function summarizeToolResult(result) {
  if (!result || typeof result !== 'object') return { ok: false };
  const out = {};
  if (result.error) out.error = result.error;
  if (result.ok != null) out.ok = result.ok;
  if (result.category) out.category = result.category;
  if (result.skipped) out.skipped = true;
  if (result.min_price != null) out.min_price = result.min_price;
  if (result.agentInstruction) out.agentInstruction = String(result.agentInstruction).slice(0, 240);
  if (Array.isArray(result.apartments)) {
    out.apartments = result.apartments.slice(0, 3).map((row) => ({
      id: row.apartment?.apartmentId,
      link: row.apartment?.link,
      price: row.apartment?.price,
      area: row.apartment?.area,
      type: row.apartment?.type,
    }));
  }
  if (result.apartment) {
    out.apartment = {
      id: result.apartment.apartmentId,
      link: result.apartment.link,
      title: result.apartment.title,
    };
  }
  return out;
}

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
  const model = config.model || 'bitrix/bitrixgpt-5.5-thinking';
  let knowledgeBase = config.knowledgeBase || loadKnowledgeBase();
  const pollMs = config.pollMs || 3000;
  const catalogRefreshMs = config.catalogRefreshMs || 60 * 60 * 1000;
  const lineId = process.env.OPENLINE_ID || null;
  let offset;
  let stopped = false;
  let busy = false;
  let lastCatalogRefresh = null;
  const store = new DialogStore();
  /** @type {Set<string>} */
  const processedMessageIds = new Set();
  const log = [];
  /** @type {Map<string, {startedAt: number, responses: number[], credits: number, messages: number}>} */
  const chatMetrics = new Map();

  function pushLog(entry) {
    log.push({ ...entry, at: new Date().toISOString() });
    if (log.length > 200) log.shift();
  }

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

  /**
   * @param {string} dialogId
   * @param {number|string|null} chatId
   */
  function metricsFor(dialogId, chatId) {
    const key = String(chatId || dialogId);
    if (!chatMetrics.has(key)) {
      chatMetrics.set(key, {
        startedAt: Date.now(),
        responses: [],
        credits: 0,
        messages: 0,
        dialogId: String(dialogId),
        chatId: chatId != null ? String(chatId) : String(dialogId),
      });
    }
    return chatMetrics.get(key);
  }

  /**
   * @param {import('./state.js').DialogState} state
   * @returns {Promise<string>}
   */
  async function buildOperatorSummary(state) {
    const skill = await loadTemplate('skill:operator_summary');
    const facts = [
      `category=${state.category || ''}`,
      `move_in=${state.routingMoveInDate || state.savedMoveInDate || ''}`,
      `period=${state.period || ''}`,
      `budget=${state.budget || ''}`,
      `types=${(state.types || []).join(',')}`,
      `area=${(state.area || []).join(',')}`,
      `unit=${state.unitNumber || ''}`,
      `links=${state.shownLinks.slice(0, 3).join(' ')}`,
      `transcript=${state.transcript.slice(-8).join(' | ')}`,
    ].join('\n');
    try {
      const completion = await chatCompletions(apiKey, {
        model,
        temperature: 0.1,
        messages: [
          { role: 'system', content: skill || 'Summarize for operator in 3 short bullets.' },
          { role: 'user', content: facts },
        ],
      });
      return String(completion?.choices?.[0]?.message?.content || '').trim();
    } catch {
      return [
        `Результат: category ${state.category || '—'}; budget ${state.budget || '—'}; move-in ${state.routingMoveInDate || '—'}`,
        state.shownLinks[0] ? `Ссылки: ${state.shownLinks[0]}` : null,
        'Следующее действие: продолжить диалог с клиентом',
      ]
        .filter(Boolean)
        .join('\n');
    }
  }

  /**
   * @param {string} dialogId
   * @param {number|string} chatId
   * @param {import('./state.js').DialogState} state
   * @param {'A'|'B'} mode
   * @param {string} reason
   * @param {string} [notifyText]
   */
  async function runTerminal(dialogId, chatId, state, mode, reason, notifyText = '') {
    if (state.handedOff && !state.terminalAPendingLeave && mode !== 'B') return;
    hydrateStateFromTranscript(state);
    enrichStateForLeave(state);
    const summary = await buildOperatorSummary(state);
    if (mode === 'A' && notifyText) {
      try {
        await sendMessage(apiKey, botId, dialogId, { message: notifyText });
      } catch {
        // ignore
      }
    }
    try {
      await sendMessage(apiKey, botId, dialogId, {
        message: `Operator summary:\n${summary}`,
      });
    } catch {
      // ignore summary delivery
    }
    state.handedOff = true;
    state.terminalAPendingLeave = false;
    if (crmEnabled()) {
      try {
        state.extras.botChatStatus = 'disconnected';
        await writeChatStatus(state, 'disconnected');
        if (!state.extras.leaveCrmWritten) {
          await writeLeaveSnapshot(state);
          state.extras.crmWrites = [
            ...(state.extras.crmWrites || []),
            { tool: 'leave_chat', ok: true, source: 'runTerminal' },
          ];
        }
        state.extras.crmWrites = [
          ...(state.extras.crmWrites || []),
          { tool: 'set_wikibot_in_chat_status', ok: true, source: 'runTerminal' },
        ];
      } catch (err) {
        pushLog({ question: reason, answer: '', handedOff: true, error: `crm_leave: ${err.message}`, dialogId });
      }
    }
    if (chatId != null) {
      try {
        await handoffToOperator(apiKey, botId, chatId);
      } catch (err) {
        pushLog({ question: reason, answer: '', handedOff: true, error: `handoff: ${err.message}`, dialogId });
      }
    }
    const m = metricsFor(dialogId, chatId);
    const now = Date.now();
    await upsertChat({
      chat_id: m.chatId,
      dialog_id: m.dialogId,
      deal_id: state.dealId || null,
      started_at: new Date(m.startedAt).toISOString().slice(0, 19).replace('T', ' '),
      ended_at: new Date(now).toISOString().slice(0, 19).replace('T', ' '),
      messages_count: m.messages,
      duration_sec: Math.round((now - m.startedAt) / 1000),
      first_response_sec: m.responses[0] ?? null,
      avg_response_sec: m.responses.length
        ? Math.round(m.responses.reduce((a, b) => a + b, 0) / m.responses.length)
        : null,
      max_response_sec: m.responses.length ? Math.max(...m.responses) : null,
      escalated: 1,
      escalate_reason: reason.slice(0, 255),
      escalate_bucket: bucketEscalateReason(reason),
      credits: m.credits,
      tags: state.activeScenario || state.category || null,
      category: state.category,
      line_id: lineId,
    }).catch(() => {});
  }

  /**
   * @param {object} event
   */
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
    if (markProcessed(dedupeKey)) return;

    const state = store.get(dialogId);
    if (!state.extras?.restoredFromDb) {
      const restored = await restoreDialogFromDb(dialogId, chatId, state);
      if (restored) hydrateStateFromTranscript(state);
    }
    if (state.handedOff) return;

    const started = Date.now();
    const m = metricsFor(dialogId, chatId);
    m.messages += 1;

    if (!text) {
      state.attachmentAskCount += 1;
      const lang = state.language || 'en';
      const ask = lang === 'ru'
        ? 'Пожалуйста, кратко опишите, что на вложении или какой у вас вопрос по аренде.'
        : 'Please briefly describe what is in the attachment or what rental question you have.';
      if (state.attachmentAskCount === 1) {
        await sendMessage(apiKey, botId, dialogId, { message: ask, keyboard: OPERATOR_KEYBOARD });
        return;
      }
      await runTerminal(dialogId, chatId, state, 'A', 'attachment_unreadable');
      return;
    }

    state.language = detectLang(text);
    state.transcript.push(`User: ${text}`);
    if (state.transcript.length > 40) state.transcript.splice(0, state.transcript.length - 40);
    if (!isRobotOnlyMessage(text)) {
      hydrateStateFromText(state, text);
    }

    if (state.terminalAPendingLeave) {
      await runTerminal(dialogId, chatId, state, 'A', 'terminal_a_continuity');
      return;
    }

    if (wantsOperator(text)) {
      const notify = state.language === 'ru'
        ? 'Подключаю специалиста Colife.'
        : 'Connecting a Colife specialist.';
      await runTerminal(dialogId, chatId, state, 'A', 'requested_by_user', notify);
      return;
    }

    if (!state.statusConnected) {
      state.statusConnected = true;
      state.extras.botChatStatus = 'connected';
      if (crmEnabled()) {
        try {
          await writeChatStatus(state, 'connected');
          state.extras.crmWrites = [
            ...(state.extras.crmWrites || []),
            { tool: 'set_wikibot_in_chat_status', ok: true, source: 'first_message' },
          ];
        } catch (err) {
          pushLog({ question: text, answer: '', handedOff: false, error: `crm_status: ${err.message}`, dialogId });
        }
      }
    }

    if (isRobotOnlyMessage(text)) {
      const unit = extractUnitNumber(text);
      let lookup = null;
      if (unit) {
        state.unitNumber = unit;
        const found = findByUnitNumber(unit);
        if (found) {
          state.unitLookupStatus = 'found';
          lookup = {
            title: found.title,
            link: found.link,
            area: found.area,
            type: found.type,
            price: found.price,
            availableFrom: found.availableFrom,
          };
          if (found.link && !state.shownLinks.includes(found.link)) state.shownLinks.push(found.link);
          const type = String(found.type || '').toLowerCase();
          if (!state.types.length) {
            if (type.includes('studio')) state.types = [0];
            else if (/\b1\b|1br|1-bedroom/i.test(type)) state.types = [1];
            else if (/\b2\b|2br|2-bedroom/i.test(type)) state.types = [2];
          }
          if (found.area && !state.area.length) state.area = [String(found.area)];
        } else {
          state.unitLookupStatus = 'not_found';
        }
      } else {
        state.unitLookupStatus = 'not_found';
      }
      const elapsed = Math.round((Date.now() - started) / 1000);
      m.responses.push(elapsed);
      await insertMessage({
        chat_id: m.chatId,
        dialog_id: m.dialogId,
        role: 'user',
        body: text.slice(0, 5000),
        response_sec: null,
        credits: 0,
        meta_json: JSON.stringify({ kind: 'robot_template' }),
        created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
      }).catch(() => {});
      await insertMessage({
        chat_id: m.chatId,
        dialog_id: m.dialogId,
        role: 'assistant',
        body: '',
        response_sec: elapsed,
        credits: 0,
        meta_json: JSON.stringify({
          kind: 'robot_silent',
          unitNumber: state.unitNumber,
          unitLookupStatus: state.unitLookupStatus,
          lookup,
        }),
        created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
      }).catch(() => {});
      pushLog({
        question: text.slice(0, 200),
        answer: '',
        handedOff: false,
        dialogId,
        scenario: 'robot_message',
        unitNumber: state.unitNumber,
      });
      return;
    }

    const runtime = {
      pendingAnswers: [],
      pendingHandoff: null,
      toolTrace: [],
      thinking: [],
      searchedThisTurn: false,
    };
    const robotTurn = looksLikeRobotTemplate(text);
    const toolsScope = robotTurn
      ? 'skill:robot_message'
      : (state.activeScenario ? `scenario:${state.activeScenario}` : 'main');
    const tools = await loadToolSchemas(toolsScope);
    tools.push(
      {
        type: 'function',
        function: {
          name: 'activate_scenario',
          description: 'Switch active scenario body (or main when scenario=main/null)',
          parameters: {
            type: 'object',
            properties: {
              scenario: {
                type: 'string',
                description: 'ejari|non_target|out_of_budget|postponed|sharing|short_term|urgent_move_in|main',
              },
            },
          },
        },
      },
      {
        type: 'function',
        function: {
          name: 'make_answer',
          description: 'Send a client-facing message. Only client-visible text. Never put reasoning here.',
          parameters: {
            type: 'object',
            required: ['text'],
            properties: { text: { type: 'string' } },
          },
        },
      },
      {
        type: 'function',
        function: {
          name: 'redirect_to_operator',
          description: 'Silent handoff to operator (terminal B)',
          parameters: {
            type: 'object',
            properties: { reason: { type: 'string' } },
          },
        },
      },
    );

    const instructionParts = [await buildSystemInstruction(state.activeScenario)];
    if (robotTurn) {
      const robot = await loadTemplate('skill:robot_message');
      if (robot) instructionParts.push(`# Active skill: robot_message\n${robot}`);
    }
    instructionParts.push(pickRelevantContext(knowledgeBase, text, 3500));
    instructionParts.push(`Dialog state JSON: ${JSON.stringify({
      category: state.category,
      activeScenario: state.activeScenario,
      statusConnected: state.statusConnected,
      handedOff: state.handedOff,
      introduced: state.introduced,
      routingMoveInDate: state.routingMoveInDate,
      savedMoveInDate: state.savedMoveInDate,
      apartmentType: state.apartmentType,
      budget: state.budget,
      period: state.period,
      types: state.types,
      area: state.area,
      excludeIds: state.excludeIds,
      unitNumber: state.unitNumber,
      unitLookupStatus: state.unitLookupStatus,
      days_to_checkin: state.extras?.days_to_checkin ?? null,
      planningToMove: state.planningToMove,
      dealId: state.dealId || '',
      contactId: state.contactId || '',
      shownLinks: state.shownLinks.slice(-5),
      language: state.language,
    })}`);
    if (state.unitNumber) {
      instructionParts.push(
        `Pending unit from earlier robot/request: ${state.unitNumber} (lookup=${state.unitLookupStatus || 'unknown'}). If client confirms Yes — call get_apartment_by_unit_number once if lookup is not found yet. Only say booked when tool returns not_found. If found — continue with that unit (do not ask apartment type when type is already known).`,
      );
    }
    const prior = state.transcript.slice(0, -1).slice(-16);
    if (prior.length) {
      instructionParts.push(`Dialog history (oldest→newest):\n${prior.join('\n')}`);
    }
    instructionParts.push([
      'Continue this same dialog. Do not re-introduce yourself if Dialog history already has a Bot line.',
      'Use known filters from history/state; ask only for missing ones. Never re-ask move-in date or rental period if Dialog state JSON already has routingMoveInDate/savedMoveInDate/period/days_to_checkin.',
      'Never output chain-of-thought, skill names, tool plans, or analysis to the client. Client text only via make_answer (or a short plain final answer).',
      'If budget and period are known, call search_apartments before saying there are no options or before handoff (unless terminal A/B already required by scenario).',
      'If move-in is today/tomorrow or days_to_checkin<=1, activate_scenario urgent_move_in before search.',
      'If client asks weekly / less than a month / short-term stay, activate_scenario short_term and follow that scenario (handoff after required params; do not keep probing type/budget endlessly).',
      'If client asks for room/shared accommodation, activate_scenario sharing and treat apartment type as room.',
    ].join(' '));
    const system = instructionParts.filter(Boolean).join('\n\n');

    /** @type {object[]} */
    const messages = [
      { role: 'system', content: system },
      ...prior.map((line) => {
        if (line.startsWith('User: ')) return { role: 'user', content: line.slice(6) };
        if (line.startsWith('Bot: ')) return { role: 'assistant', content: line.slice(5) };
        return { role: 'user', content: line };
      }),
      { role: 'user', content: text },
    ];

    let finalText = '';
    try {
      for (let step = 0; step < 6; step += 1) {
        const completion = await chatCompletions(apiKey, {
          model,
          temperature: 0.2,
          tools,
          tool_choice: 'auto',
          messages,
        });
        const usage = completion?.usage || {};
        const credits = Number(usage.total_tokens || usage.prompt_tokens || 0) / 1000;
        if (Number.isFinite(credits)) m.credits += credits;

        const choice = completion?.choices?.[0]?.message || {};
        if (choice.reasoning || choice.thinking || choice.reasoning_content) {
          runtime.thinking.push(String(choice.reasoning || choice.thinking || choice.reasoning_content).slice(0, 4000));
        }
        const toolCalls = choice.tool_calls || [];
        if (toolCalls.length) {
          messages.push({
            role: 'assistant',
            content: choice.content || null,
            tool_calls: toolCalls,
          });
          for (const call of toolCalls) {
            const name = call.function?.name || call.name;
            let args = {};
            try {
              args = JSON.parse(call.function?.arguments || call.arguments || '{}');
            } catch {
              args = {};
            }
            if (name === 'leave_chat' && !runtime.pendingHandoff) {
              runtime.pendingHandoff = { mode: 'A', reason: args.reason || 'leave_chat' };
            }
            if (name === 'search_apartments') runtime.searchedThisTurn = true;
            const result = await executeTool(state, name, args, runtime);
            runtime.toolTrace.push({ name, args, result: summarizeToolResult(result) });
            messages.push({
              role: 'tool',
              tool_call_id: call.id,
              content: JSON.stringify(result),
            });
          }
          if (state.handedOff || runtime.pendingHandoff) break;
          continue;
        }

        finalText = String(choice.content || '').trim();
        break;
      }
    } catch (err) {
      finalText = FALLBACK_TEXT[state.language || detectLang(text)];
      runtime.pendingHandoff = { mode: 'A', reason: `ai_error:${err.message}` };
      pushLog({ question: text, answer: finalText, handedOff: true, error: err.message, dialogId });
    }

    runtime.pendingAnswers = runtime.pendingAnswers
      .map((a) => sanitizeClientText(a))
      .filter((a) => a && !looksLikeReasoningLeak(a));
    finalText = sanitizeClientText(finalText);
    if (looksLikeReasoningLeak(finalText)) finalText = '';
    if (
      !runtime.pendingAnswers.length
      && !finalText
      && !runtime.pendingHandoff
      && !state.handedOff
      && !state.terminalAPendingLeave
    ) {
      hydrateStateFromTranscript(state);
      finalText = clarifyFallback(state);
    }

    for (const answer of runtime.pendingAnswers) {
      await sendMessage(apiKey, botId, dialogId, {
        message: answer,
        keyboard: OPERATOR_KEYBOARD,
      });
      state.transcript.push(`Bot: ${answer}`);
    }

    if (finalText && !runtime.pendingAnswers.length) {
      let answer = finalText;
      try {
        const parsed = JSON.parse(finalText);
        if (parsed?.answer) answer = sanitizeClientText(String(parsed.answer));
        if (parsed?.handoff) {
          runtime.pendingHandoff = runtime.pendingHandoff || {
            mode: 'A',
            reason: 'model_handoff',
          };
        }
        if (parsed?.scenario) state.activeScenario = String(parsed.scenario);
      } catch {
        // plain text
      }
      if (looksLikeReasoningLeak(answer)) answer = '';
      answer = sanitizeClientText(answer);
      if (answer) {
        await sendMessage(apiKey, botId, dialogId, {
          message: answer,
          keyboard: OPERATOR_KEYBOARD,
        });
        state.transcript.push(`Bot: ${answer}`);
        state.introduced = true;
      }
    }

    const elapsed = Math.round((Date.now() - started) / 1000);
    m.responses.push(elapsed);
    const clientAnswer = runtime.pendingAnswers.join('\n') || finalText || '';
    await insertMessage({
      chat_id: m.chatId,
      dialog_id: m.dialogId,
      role: 'user',
      body: text.slice(0, 5000),
      response_sec: null,
      credits: 0,
      meta_json: null,
      created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
    }).catch(() => {});
    await insertMessage({
      chat_id: m.chatId,
      dialog_id: m.dialogId,
      role: 'assistant',
      body: clientAnswer.slice(0, 5000),
      response_sec: elapsed,
      credits: m.credits,
      meta_json: JSON.stringify({
        category: state.category,
        scenario: state.activeScenario,
        tools: runtime.toolTrace.slice(-12),
        thinking: runtime.thinking.slice(-3),
        searchedThisTurn: runtime.searchedThisTurn,
        unitNumber: state.unitNumber,
        filters: {
          budget: state.budget,
          period: state.period,
          types: state.types,
          area: state.area,
          routingMoveInDate: state.routingMoveInDate,
        },
      }),
      created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
    }).catch(() => {});

    await upsertChat({
      chat_id: m.chatId,
      dialog_id: m.dialogId,
      deal_id: state.dealId || null,
      started_at: new Date(m.startedAt).toISOString().slice(0, 19).replace('T', ' '),
      ended_at: null,
      messages_count: m.messages,
      duration_sec: Math.round((Date.now() - m.startedAt) / 1000),
      first_response_sec: m.responses[0] ?? null,
      avg_response_sec: Math.round(m.responses.reduce((a, b) => a + b, 0) / m.responses.length),
      max_response_sec: Math.max(...m.responses),
      escalated: 0,
      escalate_reason: null,
      escalate_bucket: null,
      credits: m.credits,
      tags: state.activeScenario || state.category || null,
      category: state.category,
      line_id: lineId,
    }).catch(() => {});

    if (runtime.pendingHandoff || state.handedOff || state.terminalAPendingLeave) {
      const mode = runtime.pendingHandoff?.mode || (state.terminalAPendingLeave ? 'A' : 'A');
      const reason = runtime.pendingHandoff?.reason || 'leave_chat';
      const needNotify = mode === 'A' && !runtime.pendingAnswers.length;
      const notify = needNotify
        ? (state.language === 'ru'
          ? 'Подключаю специалиста Colife.'
          : 'Connecting a Colife specialist.')
        : '';
      await runTerminal(dialogId, chatId, state, mode, reason, notify);
    }

    pushLog({
      question: text,
      answer: runtime.pendingAnswers.join('\n') || finalText,
      handedOff: Boolean(state.handedOff),
      dialogId,
      category: state.category,
      scenario: state.activeScenario,
    });
  }

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
          if (err.code === 'BOT_EVENTS_BUSY' || err.status === 409) return;
          throw err;
        }
        const events = body?.data?.events || body?.data || [];
        const list = Array.isArray(events) ? events : [];
        for (const event of list) {
          if (!shouldHandleMessageEvent(event)) continue;
          try {
            await handleMessage(event);
          } catch (err) {
            pushLog({ question: '', answer: '', handedOff: false, error: err.message });
          }
        }
        const next = body?.data?.nextOffset ?? body?.meta?.nextOffset ?? body?.nextOffset;
        if (next != null && next !== offset && list.length) {
          offset = next;
          hasMore = list.length >= 50;
        } else {
          hasMore = false;
          if (next != null) offset = next;
        }
      }
    } finally {
      busy = false;
    }
  }

  async function refreshCatalog() {
    try {
      const result = await refreshCatalogKb();
      lastCatalogRefresh = new Date().toISOString();
      knowledgeBase = loadKnowledgeBase();
      pushLog({ question: '', answer: '', handedOff: false, error: result.ok ? null : result.error, catalog: result.count });
    } catch (err) {
      pushLog({ question: '', answer: '', handedOff: false, error: `catalog: ${err.message}` });
    }
  }

  function start() {
    refreshCatalog();
    const pollTimer = setInterval(() => {
      pollOnce().catch((err) => pushLog({ question: '', answer: '', handedOff: false, error: err.message }));
    }, pollMs);
    const catalogTimer = setInterval(() => {
      refreshCatalog();
    }, catalogRefreshMs);
    pollOnce().catch(() => {});

    return {
      stop() {
        stopped = true;
        clearInterval(pollTimer);
        clearInterval(catalogTimer);
      },
      getStatus() {
        return {
          botId,
          model,
          lineId,
          offset: offset ?? null,
          lastCatalogRefresh,
          log: log.slice(-20),
        };
      },
    };
  }

  return { start, pollOnce, handleMessage, store };
}
