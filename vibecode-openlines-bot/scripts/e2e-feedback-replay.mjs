/**
 * Live AI replay of tech-test dialogs (feedback 87323… → chats 87447…87505).
 * Usage:
 *   node scripts/e2e-feedback-replay.mjs
 *   node scripts/e2e-feedback-replay.mjs fb01_ejari
 *   node scripts/e2e-feedback-replay.mjs --count=1
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createBotWorker } from '../lib/bot.js';
import { setVibeTestHooks } from '../lib/vibe.js';
import {
  crmEnabled,
  createDeal,
  getDeal,
  readDealOlFields,
} from '../lib/crm.js';
import { enrichStateForLeave } from '../lib/scenarios.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(__dirname, '..');

function loadEnvFile() {
  const envPath = path.join(root, '.env');
  if (!fs.existsSync(envPath)) return;
  for (const line of fs.readFileSync(envPath, 'utf8').split('\n')) {
    const m = line.match(/^([A-Z0-9_]+)=(.*)$/);
    if (!m) continue;
    if (process.env[m[1]] == null) process.env[m[1]] = m[2];
  }
}

loadEnvFile();
process.env.CRM_DEAL_TITLE = 'Test AI Feedback';

const apiKey = process.env.VIBE_API_KEY;
const botId = process.env.BOT_ID || '43591';
const model = process.env.AI_MODEL || 'bitrix/bitrixgpt-5.5-thinking';
if (!apiKey) {
  console.error('VIBE_API_KEY required');
  process.exit(1);
}

const robot = (unit, source = 'Bayut') =>
  `[B]Отправлено роботом[/B] Hello, my name is Elizabeth, I am Colife Dubai manager!\n\nWe have received your apartment request from ${source} for unit ${unit}\n\nPlease confirm us if we should keep your request open and continue processing it. | [Yes] | [No, thanks]`;

/** Cyrillic letters — used to flag RU replies after EN client turns */
const CYR = /[А-Яа-яЁё]/;

/**
 * @typedef {{ scenario: string, feedbackId: string, chatId: string, turns: string[], expect?: (RegExp|null)[], crmExpect?: object, checks?: object }} FeedbackScenario
 */

/** @type {FeedbackScenario[]} */
const scenarios = [
  {
    scenario: 'fb01_ejari',
    feedbackId: '87323',
    chatId: '87447',
    turns: [
      robot('from our catalogue', 'Website'),
      'Yes',
      'for a year , next week',
      '1br , 15k',
      'what is ejari?',
      'yes but I do not have emirates id',
    ],
    expect: [
      null,
      /move|when|date|long|how long|year|type|budget|ejari|apartment|help|confirm/i,
      /ejari|year|type|budget|1.?br|emirates|week|move/i,
      /ejari|emirates|cheque|contract|year|specialist|operator/i,
      /ejari|emirates|id|utility|dewa|cheque|official|contract/i,
      /specialist|operator|transfer|connect|подключа|менеджер/i,
    ],
    crmExpect: {
      requireDeal: true,
      tools: ['qualify_lead', 'set_wikibot_in_chat_status'],
      category: /ejari/i,
      moveInDate: true,
    },
    checks: {
      expectEnRepliesAfter: 1,
      moveInNotNull: true,
    },
  },
  {
    scenario: 'fb02_non_target_jobs',
    feedbackId: '87327',
    chatId: '87451',
    turns: [
      'Dubai',
      'хотел работу присмотреть, у вас есть?',
    ],
    expect: [
      /colife|help|housing|dubai|здравству|помощ/i,
      /specialist|operator|transfer|connect|специалист|менеджер|подключа|работа|аренда|housing|job/i,
    ],
    crmExpect: {
      requireDeal: true,
      tools: ['set_wikibot_in_chat_status'],
      category: /non.?target|no.?information/i,
    },
    checks: {
      categoryRequired: true,
    },
  },
  {
    scenario: 'fb03_non_target_landlord',
    feedbackId: '87329',
    chatId: '87455',
    turns: [
      robot('882.1'),
      'Здравствуйте! Можно сдать у вас мою квартиру?',
    ],
    expect: [
      null,
      /specialist|operator|transfer|connect|специалист|менеджер|подключа|сдать|landlord|собствен/i,
    ],
    crmExpect: {
      requireDeal: true,
      tools: ['set_wikibot_in_chat_status'],
      category: /non.?target|no.?information/i,
    },
    checks: {
      categoryRequired: true,
    },
  },
  {
    scenario: 'fb04_out_of_budget',
    feedbackId: '87331',
    chatId: '87459',
    turns: [
      robot('1037.1', 'Propertyfinder'),
      'Yes',
      '?',
      'english pls',
      'Want to start contract by 22 nov and 5000 max budget for 4 months monthly payement',
      'Can i check first',
      'what else options do you have?',
      'the price are too high',
      'can\'t increase my budget',
      'what dates can you offer to fit my budget?',
    ],
    expect: [
      null,
      /booked|available|alternative|move|long|срок|option|1037|заброн/i,
      /booked|month|long|срок|period|alternative|english|option/i,
      /booked|month|period|alternative|option|budget|english/i,
      /5000|budget|option|aed|alternative|sharing|catalog|area|date|found|min|5799|6999/i,
      /option|link|aed|budget|alternative|furjan|studio|sharing|catalog/i,
      /option|link|aed|budget|alternative|area|sharing|catalog|high/i,
      /include|dewa|wifi|value|budget|sharing|area|option|catalog|aed/i,
      /sharing|room|area|date|catalog|budget|option|specialist|operator/i,
      /date|area|sharing|room|option|budget|catalog|aed|specialist|operator/i,
    ],
    crmExpect: {
      requireDeal: true,
      tools: ['qualify_lead', 'set_wikibot_in_chat_status'],
      category: /out.?of.?budget/i,
      moveInDate: /2026-11-22|22.?11.?2026|11.?22/i,
      budget: /.+/i,
    },
    checks: {
      expectEnRepliesAfter: 3,
      noInventedSharingPrices: true,
    },
  },
  {
    scenario: 'fb05_postponed',
    feedbackId: '87333',
    chatId: '87465',
    turns: [
      `${robot('7.1')} Yes`,
      '15 декабря',
    ],
    expect: [
      /7\.1|unit|available|move|when|date|заезд|link|neva|jvc|1br|apartment/i,
      /december|15|postpon|reserv|catalog|week|specialist|operator|target|confirm|long|срок/i,
    ],
    crmExpect: {
      requireDeal: true,
      tools: ['qualify_lead', 'set_wikibot_in_chat_status'],
      category: /postponed/i,
      moveInDate: /2026-12-15|15.?12.?2026|12.?15/i,
    },
    checks: {
      moveInNotSkuPoison: [/2027-01-07/, /07.?01.?2027/, /1.?7.?2027/],
      expectEnRepliesAfter: null,
    },
  },
  {
    scenario: 'fb06_target_990',
    feedbackId: '87337',
    chatId: '87469',
    turns: [
      `${robot('990.1')} Yes`,
      'english please 29 dec',
      'month',
      'for 1 month',
    ],
    expect: [
      /990|unit|available|from|move|when|date|link|studio|neva|jvc/i,
      /month|long|срок|period|available|from|december|29|option/i,
      /month|available|from|option|link|aed|book|view|specialist|postpon|reserv/i,
      /month|available|from|option|link|aed|book|view|specialist|postpon|reserv/i,
    ],
    crmExpect: {
      requireDeal: true,
      category: /target|supertarget|postponed/i,
      moveInDate: /2026-12-29|29.?12.?2026|12.?29/i,
    },
    checks: {
      expectEnRepliesAfter: 1,
      noQuestionLoop: true,
      availableFromMentioned: true,
    },
  },
  {
    scenario: 'fb07_target_853',
    feedbackId: '87339',
    chatId: '87473',
    turns: [
      `${robot('853.1', 'PropertyFinder')} Yes`,
      '23 november',
      'english 3 month',
      'why is it important? i want this apartment',
      'yes',
      'can i move in on 23 november?',
      'for 6 month what price?',
      'what includes the price?',
    ],
    expect: [
      /853|unit|available|from|move|when|date|link|1br|rise|jvc/i,
      /month|long|budget|available|from|november|option/i,
      /budget|aed|price|available|from|month|option/i,
      /budget|price|aed|available|from|book|view|november/i,
      /view|book|available|from|november|specialist|operator|proceed/i,
      /available|from|november|december|30|23|sorry|alternative|book|view|yes|no/i,
      /price|aed|6.?month|month|variant|discount|10399|specialist/i,
      /include|dewa|wifi|specialist|operator|fee|price/i,
    ],
    crmExpect: {
      requireDeal: true,
      moveInDate: /2026-11-23|23.?11.?2026|11.?23/i,
    },
    checks: {
      expectEnRepliesAfter: 2,
      moveInNotSkuPoison: [/2027-02-22/, /22.?02.?2027/],
      availableFromMentioned: true,
    },
  },
  {
    scenario: 'fb08_sharing',
    feedbackId: '87343',
    chatId: '87479',
    turns: [
      'Dubai',
      'I want to rent master-room from 20 november',
      '3 month and 4k',
      'any',
    ],
    expect: [
      /colife|help|housing|dubai/i,
      /month|long|budget|period|area|room|specialist|operator/i,
      /area|specialist|operator|transfer|connect|budget|room/i,
      /specialist|operator|transfer|connect|подключа|менеджер|summary|^$/i,
    ],
    crmExpect: {
      requireDeal: true,
      tools: ['qualify_lead', 'set_wikibot_in_chat_status'],
      category: /sharing/i,
      apartmentType: /room|bedspace/i,
    },
    checks: {
      expectEnRepliesAfter: 0,
      noDuplicateBurst: true,
    },
  },
  {
    scenario: 'fb09_short_term',
    feedbackId: '87347',
    chatId: '87483',
    turns: [
      'Hi',
      'Do you have any weekly rental options in Azizi riveira',
    ],
    expect: [
      /colife|help|housing|dubai/i,
      /week|short|specialist|operator|transfer|connect|minimum|day/i,
    ],
    crmExpect: {
      requireDeal: true,
      tools: ['qualify_lead', 'set_wikibot_in_chat_status'],
      category: /short.?term/i,
    },
    checks: {
      stayDuration: /1-14_days|1.?14/i,
    },
  },
  {
    scenario: 'fb10_supertarget',
    feedbackId: '87349',
    chatId: '87487',
    turns: [
      robot('107.1'),
      'Yes',
      'Hello',
      'want to move in today',
      'year 15k 1br',
    ],
    expect: [
      null,
      /booked|alternative|long|срок|period|107|available/i,
      /long|period|month|year|budget|type|alternative|today|urgent/i,
      /today|urgent|long|budget|type|year|option|specialist/i,
      /specialist|operator|transfer|connect|ejari|option|year|urgent/i,
    ],
    crmExpect: {
      requireDeal: true,
      category: /supertarget|urgent|ejari|target/i,
      moveInDate: true,
    },
    checks: {
      expectEnRepliesAfter: 2,
      moveInNotSkuPoison: [/2027-01-07/, /07.?01.?2027/],
      noReHello: true,
      planningUrgent: true,
    },
  },
  {
    scenario: 'fb11_target_1br_not_sharing',
    feedbackId: '87353',
    chatId: '87493',
    turns: [
      "Hey, I'm looking for an apartment for 3 months in downtown",
      '7 december I’m in dubaï I\'m looking for a room with a view of the city (and a view of the Burj, if possible) so 1 bedroom I have a dog also small dog',
      '13k AED Maximum',
    ],
    expect: [
      /move|when|date|type|budget|downtown|apartment|help/i,
      /budget|option|1.?bed|bedroom|downtown|aed|dog|view/i,
      /option|link|aed|1.?bed|bedroom|downtown|found|specialist|operator|view/i,
    ],
    crmExpect: {
      requireDeal: true,
      category: /target|supertarget/i,
      apartmentType: /1.?bedroom|1_bedroom|studio/i,
    },
    checks: {
      expectEnRepliesAfter: 0,
      mustOfferOptions: true,
      notSharing: true,
    },
  },
  {
    scenario: 'fb12_supertarget_2br',
    feedbackId: '87359',
    chatId: '87501',
    turns: [
      robot('32.1'),
      'can you send me the link of the apt ur talking about?',
      'i want to move in on 19 november',
      'please is there an option with the same price range in marina for 2 bedroom?',
      'okay let\'s increase my budget',
      '2-3 month 18000',
      '2 is good, viewing',
    ],
    expect: [
      null,
      /link|32|alexis|apartment|available|from|move|when/i,
      /month|long|budget|available|marina|2.?bed/i,
      /marina|2.?bed|option|link|aed|budget|available|no/i,
      /budget|month|marina|2.?bed|option/i,
      /option|link|aed|2.?bed|marina|found|princess|studio one/i,
      /specialist|operator|view|transfer|connect|менеджер/i,
    ],
    crmExpect: {
      requireDeal: true,
      category: /supertarget|target/i,
      apartmentType: /2.?bedroom|2_bedroom/i,
      moveInDate: /2026-11-19|19.?11.?2026|11.?19/i,
    },
    checks: {
      expectEnRepliesAfter: 1,
    },
  },
  {
    scenario: 'fb13_oob_sharing_prices',
    feedbackId: '87363',
    chatId: '87505',
    turns: [
      'Need to move immediately 4000',
      'Can i check first',
      'price is too high',
      'no',
      'ok',
      'what is this&',
      'no thanks',
    ],
    expect: [
      /budget|4000|aed|option|urgent|immediate|min|5799|sharing|increase/i,
      /option|link|aed|budget|5799|furjan|alternative|sharing/i,
      /include|dewa|wifi|budget|sharing|option|aed|value/i,
      /sharing|room|specialist|operator|catalog|budget|option/i,
      /sharing|room|specialist|operator|budget|month|how long/i,
      /sharing|room|month|specialist|operator|budget|mean/i,
      /specialist|operator|transfer|connect|подключа|менеджер|^$/i,
    ],
    crmExpect: {
      requireDeal: true,
      category: /out.?of.?budget|sharing/i,
    },
    checks: {
      expectEnRepliesAfter: 0,
      noInventedSharingPrices: true,
    },
  },
];

function makeEvent(dialogId, text, n) {
  return {
    type: 'ONIMBOTV2MESSAGEADD',
    eventId: `${dialogId}-${n}`,
    data: {
      bot: { id: Number(botId) },
      chat: { id: 900000 + Number(String(dialogId).replace(/\D/g, '').slice(-4) || 1), dialogId },
      message: {
        id: Date.now() + n,
        text,
        authorId: 111,
        date: new Date().toISOString(),
        isSystem: false,
      },
    },
  };
}

function verifyCrm(item, state, dealFields) {
  const checks = [];
  const exp = item.crmExpect;
  if (!exp) return { ok: true, checks };

  const writes = state.extras?.crmWrites || [];
  const toolsCalled = writes.map((w) => w.tool);

  if (exp.requireDeal && crmEnabled()) {
    checks.push({ name: 'dealId', ok: Boolean(state.dealId), got: state.dealId || null });
  }
  if (exp.tools?.length) {
    for (const tool of exp.tools) {
      checks.push({ name: `tool:${tool}`, ok: toolsCalled.includes(tool), got: toolsCalled });
    }
    const failedWrite = writes.find((w) => w.ok === false);
    checks.push({ name: 'crm_writes_ok', ok: !failedWrite, got: failedWrite || 'all_ok' });
  }
  if (exp.category) {
    const live = dealFields?.category || state.category || '';
    checks.push({ name: 'category', ok: exp.category.test(String(live)), got: live });
  }
  if (exp.chatStatus) {
    const live = dealFields?.chatStatus || state.extras?.botChatStatus || '';
    checks.push({ name: 'chatStatus', ok: exp.chatStatus.test(String(live)), got: live });
  }
  if (exp.apartmentType) {
    const live = dealFields?.apartmentType || state.apartmentType || '';
    checks.push({ name: 'apartmentType', ok: exp.apartmentType.test(String(live)), got: live });
  }
  if (exp.budget) {
    const live = dealFields?.budget || state.budget || '';
    checks.push({ name: 'budget', ok: exp.budget.test(String(live)), got: live });
  }
  if (exp.moveInDate) {
    const live = dealFields?.moveInDate || state.savedMoveInDate || state.routingMoveInDate || '';
    const ok = typeof exp.moveInDate === 'boolean'
      ? Boolean(live)
      : exp.moveInDate.test(String(live));
    checks.push({ name: 'moveInDate', ok, got: live });
  }

  return { ok: checks.every((c) => c.ok), checks };
}

function runExtraChecks(item, turns, state, dealFields) {
  const checks = [];
  const c = item.checks || {};
  const replies = turns.map((t) => t.reply || '');

  if (typeof c.expectEnRepliesAfter === 'number') {
    const from = c.expectEnRepliesAfter;
    const bad = [];
    for (let i = from; i < replies.length; i += 1) {
      const user = item.turns[i] || '';
      // Skip pure robot-template first turn noise; after EN client turns, bot must stay EN
      if (CYR.test(user) && !/[A-Za-z]{3,}/.test(user)) continue;
      if (CYR.test(replies[i])) bad.push(i + 1);
    }
    checks.push({
      name: 'language_en_after_en_user',
      ok: bad.length === 0,
      got: bad.length ? `RU in turns ${bad.join(',')}` : 'ok',
    });
  }

  if (c.moveInNotNull) {
    const live = dealFields?.moveInDate || state.savedMoveInDate || state.routingMoveInDate || '';
    checks.push({ name: 'moveIn_filled', ok: Boolean(live), got: live || null });
  }

  if (c.moveInNotSkuPoison) {
    const live = String(dealFields?.moveInDate || state.savedMoveInDate || state.routingMoveInDate || '');
    const hit = c.moveInNotSkuPoison.some((re) => re.test(live));
    checks.push({ name: 'moveIn_not_sku_poison', ok: !hit && Boolean(live), got: live || null });
  }

  if (c.categoryRequired) {
    const live = dealFields?.category || state.category || '';
    checks.push({ name: 'category_not_empty', ok: Boolean(live), got: live || null });
  }

  if (c.stayDuration) {
    const live = dealFields?.stayDuration
      || state.stayDuration
      || state.extras?.leaveSnapshot?.stay_duration
      || '';
    checks.push({ name: 'stayDuration', ok: c.stayDuration.test(String(live)), got: live });
  }

  if (c.planningUrgent) {
    const live = dealFields?.planningToMove || state.planningToMove || '';
    checks.push({
      name: 'planning_urgent',
      ok: /urgent|today|immediate|asap/i.test(String(live)),
      got: live,
    });
  }

  if (c.notSharing) {
    const cat = String(dealFields?.category || state.category || '');
    const apt = String(dealFields?.apartmentType || state.apartmentType || '');
    checks.push({
      name: 'not_sharing',
      ok: !/sharing/i.test(cat) && !/^room$/i.test(apt),
      got: { category: cat, apartmentType: apt },
    });
  }

  if (c.mustOfferOptions) {
    const joined = replies.join('\n');
    const ok = /colife\.ae\/catalog|https?:\/\/\S+/i.test(joined)
      || /found|option|available.*(studio|bedroom|apartment)/i.test(joined);
    checks.push({ name: 'offered_options', ok, got: ok ? 'has options/links' : 'no options in replies' });
  }

  if (c.availableFromMentioned) {
    const joined = replies.join('\n');
    const ok = /available from|доступн|from (nov|dec|jan|feb|mar|apr|may|jun|jul|aug|sep|oct)/i.test(joined);
    checks.push({ name: 'availableFrom_mentioned', ok, got: ok ? 'mentioned' : 'missing' });
  }

  if (c.noQuestionLoop) {
    const norm = replies.map((r) => r.replace(/\s+/g, ' ').trim().toLowerCase()).filter(Boolean);
    let loop = false;
    for (let i = 1; i < norm.length; i += 1) {
      if (norm[i] && norm[i] === norm[i - 1] && /\?/.test(norm[i])) loop = true;
    }
    checks.push({ name: 'no_question_loop', ok: !loop, got: loop ? 'same question repeated' : 'ok' });
  }

  if (c.noDuplicateBurst) {
    let dup = false;
    for (const r of replies) {
      const parts = r.split(/\n+/).map((x) => x.trim()).filter(Boolean);
      const seen = new Set();
      for (const p of parts) {
        const key = p.toLowerCase();
        if (seen.has(key) && p.length > 20) dup = true;
        seen.add(key);
      }
      // same sentence twice in one bubble
      if (/(.{30,}?)\1/i.test(r.replace(/\s+/g, ' '))) dup = true;
    }
    checks.push({ name: 'no_duplicate_burst', ok: !dup, got: dup ? 'duplicate text in reply' : 'ok' });
  }

  if (c.noReHello) {
    const hellos = replies.filter((r) => /^(hello|hi|здравствуй)/i.test(r.trim())).length;
    checks.push({ name: 'no_rehello', ok: hellos <= 1, got: `hello-like replies: ${hellos}` });
  }

  if (c.noInventedSharingPrices) {
    const joined = replies.join('\n');
    // Flag room/sharing prices narrated as concrete AED amounts
    const invented = /(?:room|sharing|shared).{0,80}\b(?:from\s+)?(?:approximately\s+)?\d{1,2}[,.]?\d{3}\s*(?:AED|aed)/i.test(joined)
      || /(?:rooms?|sharing).{0,40}start(?:s|ing)?.{0,40}\d{1,2}[,.]?\d{3}/i.test(joined);
    checks.push({
      name: 'no_invented_sharing_prices',
      ok: !invented,
      got: invented ? 'narrated sharing/room AED price' : 'ok',
    });
  }

  return { ok: checks.every((x) => x.ok), checks };
}

async function runScenario(item) {
  /** @type {string[]} */
  const outbound = [];
  let handedOff = false;
  setVibeTestHooks({
    async sendMessage(_k, _b, _d, fields) {
      const msg = String(fields?.message || '');
      outbound.push(msg);
      return { success: true };
    },
    async handoffToOperator() {
      handedOff = true;
      return { success: true };
    },
  });

  const worker = createBotWorker({
    apiKey,
    botId,
    model,
    pollMs: 60_000,
    catalogRefreshMs: 24 * 60 * 60 * 1000,
  });
  const dialogId = `e2e-fb-${item.scenario}-${Date.now()}`;
  const state = worker.store.get(dialogId);

  let dealId = null;
  if (crmEnabled()) {
    try {
      dealId = await createDeal('Test AI Feedback');
      state.dealId = dealId;
      console.log(`[${item.scenario}] CRM deal #${dealId} (orig chat ${item.chatId} / fb ${item.feedbackId})`);
    } catch (err) {
      console.log(`[${item.scenario}] CRM createDeal failed (${err.message}) — continuing with local state`);
    }
  }

  const turns = [];

  for (let i = 0; i < item.turns.length; i += 1) {
    const before = outbound.length;
    const userText = item.turns[i];
    const started = Date.now();
    await worker.handleMessage(makeEvent(dialogId, userText, i + 1));
    const replies = outbound.slice(before);
    const reply = replies.filter((m) => !m.startsWith('Operator summary:')).join('\n') || replies.join('\n');
    const expect = item.expect?.[i];
    const ok = expect ? expect.test(reply) : (i === 0 && !reply.trim() ? true : Boolean(reply.trim()) || handedOff);
    turns.push({
      n: i + 1,
      user: userText,
      reply,
      ms: Date.now() - started,
      ok,
      expect: expect ? String(expect) : null,
      handedOff,
      cyrillic: CYR.test(reply),
    });
    console.log(`\n[${item.scenario}] #${i + 1} ${ok ? 'PASS' : 'FAIL'} (${Date.now() - started}ms)${CYR.test(reply) ? ' [RU]' : ''}`);
    console.log(`USER: ${userText.slice(0, 200)}${userText.length > 200 ? '…' : ''}`);
    console.log(`BOT: ${reply.slice(0, 600)}${reply.length > 600 ? '…' : ''}`);
    if (!ok && expect) console.log(`EXPECT: ${expect}`);
    if (handedOff || state.handedOff) {
      console.log(`[${item.scenario}] stop after handoff (remaining turns skipped)`);
      break;
    }
  }

  // Ensure leave snapshot fields exist for asserts even if CRM is off.
  if (state.handedOff || handedOff) {
    enrichStateForLeave(state);
  }

  /** @type {object|null} */
  let dealFields = null;
  let crm = { ok: true, checks: [] };
  if (item.crmExpect) {
    if (crmEnabled() && state.dealId) {
      try {
        const deal = await getDeal(state.dealId);
        dealFields = await readDealOlFields(deal);
        console.log(`[${item.scenario}] CRM fields:`, JSON.stringify(dealFields));
      } catch (err) {
        console.log(`[${item.scenario}] CRM get failed: ${err.message}`);
      }
    } else if (!crmEnabled()) {
      console.log(`[${item.scenario}] CRM disabled — checking local state only`);
    }
    crm = verifyCrm(item, state, dealFields);
    for (const check of crm.checks) {
      console.log(`[${item.scenario}] CRM ${check.ok ? 'PASS' : 'FAIL'} ${check.name}: ${JSON.stringify(check.got)}`);
    }
  }

  const extra = runExtraChecks(item, turns, state, dealFields);
  for (const check of extra.checks) {
    console.log(`[${item.scenario}] CHK ${check.ok ? 'PASS' : 'FAIL'} ${check.name}: ${JSON.stringify(check.got)}`);
  }

  setVibeTestHooks(null);
  const passed = turns.every((t) => t.ok) && crm.ok && extra.ok;
  return {
    scenario: item.scenario,
    feedbackId: item.feedbackId,
    chatId: item.chatId,
    passed,
    handedOff,
    turns,
    dealId: state.dealId || dealId,
    category: state.category,
    apartmentType: state.apartmentType,
    routingMoveInDate: state.routingMoveInDate,
    savedMoveInDate: state.savedMoveInDate,
    planningToMove: state.planningToMove,
    period: state.period,
    language: state.language,
    crmWrites: state.extras?.crmWrites || [],
    dealFields,
    crmChecks: crm.checks,
    extraChecks: extra.checks,
  };
}

function parseArgs(argv) {
  let only = null;
  let count = 0;
  for (const arg of argv) {
    if (arg.startsWith('--count=')) {
      count = Math.max(0, Number.parseInt(arg.slice('--count='.length), 10) || 0);
      continue;
    }
    if (arg === '--count' || arg.startsWith('-')) continue;
    only = arg;
  }
  return { only, count };
}

async function main() {
  const { only, count } = parseArgs(process.argv.slice(2));
  const base = only ? scenarios.filter((s) => s.scenario === only || s.feedbackId === only || s.chatId === only) : scenarios;
  if (!base.length) {
    console.error('No scenarios matched. Available:', scenarios.map((s) => s.scenario).join(', '));
    process.exit(1);
  }
  const target = count > 0 ? count : base.length;
  const queue = [];
  for (let i = 0; i < target; i += 1) queue.push(base[i % base.length]);

  console.log(`Feedback e2e replay · model=${model} · CRM=${crmEnabled() ? 'on' : 'off'} · scenarios=${queue.length}`);

  const results = [];
  for (let i = 0; i < queue.length; i += 1) {
    const item = queue[i];
    console.log(`\n======== ${item.scenario} (fb ${item.feedbackId} / chat ${item.chatId}) [${i + 1}/${queue.length}] ========`);
    const result = await runScenario(item);
    results.push({ ...result, runIndex: i + 1 });
    const passN = results.filter((r) => r.passed).length;
    console.log(`[progress] ${i + 1}/${queue.length} done · pass ${passN} · fail ${i + 1 - passN}`);
  }

  const outPath = path.join(root, 'data/state/e2e-feedback-last.json');
  const batchPath = path.join(root, 'data/state', `e2e-feedback-${new Date().toISOString().replace(/[:.]/g, '-')}.json`);
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  const payload = { at: new Date().toISOString(), model, target: queue.length, results };
  fs.writeFileSync(outPath, JSON.stringify(payload, null, 2));
  fs.writeFileSync(batchPath, JSON.stringify(payload, null, 2));

  const failed = results.filter((r) => !r.passed);
  console.log('\n======== SUMMARY ========');
  console.log(`chats: ${results.length} · pass ${results.length - failed.length} · fail ${failed.length}`);
  for (const r of results) {
    const crmPart = r.crmChecks?.length
      ? ` crm ${r.crmChecks.filter((c) => c.ok).length}/${r.crmChecks.length}`
      : '';
    const extraPart = r.extraChecks?.length
      ? ` chk ${r.extraChecks.filter((c) => c.ok).length}/${r.extraChecks.length}`
      : '';
    console.log(
      `${r.passed ? 'PASS' : 'FAIL'} #${r.runIndex} ${r.scenario} fb=${r.feedbackId} `
      + `(${r.turns.filter((t) => t.ok).length}/${r.turns.length}${crmPart}${extraPart}) `
      + `cat=${r.dealFields?.category || r.category || '—'} `
      + `moveIn=${r.dealFields?.moveInDate || r.savedMoveInDate || r.routingMoveInDate || '—'} `
      + `deal=${r.dealId || '—'}`,
    );
  }
  console.log(`saved ${outPath}`);
  console.log(`saved ${batchPath}`);
  process.exit(failed.length ? 1 : 0);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
