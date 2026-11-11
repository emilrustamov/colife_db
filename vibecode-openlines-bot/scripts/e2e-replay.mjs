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
process.env.CRM_DEAL_TITLE = 'Test AI';

const apiKey = process.env.VIBE_API_KEY;
const botId = process.env.BOT_ID || '43591';
const model = process.env.AI_MODEL || 'bitrix/bitrixgpt-5.5-thinking';
if (!apiKey) {
  console.error('VIBE_API_KEY required');
  process.exit(1);
}

/**
 * @typedef {{ scenario: string, turns: string[], expect?: RegExp[], crmExpect?: {
 *   tools?: string[],
 *   category?: RegExp,
 *   chatStatus?: RegExp,
 *   apartmentType?: RegExp,
 *   budget?: RegExp,
 *   moveInDate?: boolean|RegExp,
 *   requireDeal?: boolean,
 * } }} E2eScenario
 */

/** @type {E2eScenario[]} */
const scenarios = [
  {
    scenario: 'marina_studio_budget',
    turns: [
      'Hey',
      "Hi I'm looking for a studio apartment for myself in Dubai Marina. Modern, fully furnished. Budget like 7500 AED. Need options with prices and photos.",
      '20 21 this month',
      'Monthly rental, but I will stay for like 4-6 months',
      'This is kinda out of the budget',
      'I can do 8000-7700 maybe',
      'Maybe in dubai hills or JLT?',
      'How is the wifi there?',
    ],
    expect: [
      /colife|help|looking|studio|marina|hi|здравству/i,
      /move|when|date|заезд|check.?in|period|how long|срок|studio|option|link|colife\.ae/i,
      /month|срок|period|long|how long|option|link|budget|found|studio|marina/i,
      /option|link|studio|budget|aed|marina|colife\.ae|found|month/i,
      /budget|area|option|link|alternative|jabal|discovery|arjan|jlt|hills|marina|aed/i,
      /option|link|aed|budget|marina|jlt|hills|still|no|found|alternative/i,
      /jlt|hills|option|link|aed|studio|found|colife\.ae/i,
      /wifi|wi-?fi|internet|included|speed|specialist|operator|confirm/i,
    ],
    crmExpect: {
      requireDeal: true,
      tools: ['set_wikibot_in_chat_status'],
      chatStatus: /connected|disconnected/i,
    },
  },
  {
    scenario: 'unit_958_postponed_window',
    turns: [
      'Hi, interested in unit 958.1 from Propertyfinder',
      'Yes keep the request open',
      '15/12/2026',
      '3 months approx',
      'I mean start of December this year, less than 3 weeks from now',
    ],
    expect: [
      /958|unit|available|move|when|date|заезд|hills|2br|apartment|link|colife/i,
      /move|when|date|заезд|long|срок|period|available|link|confirm/i,
      /reserv|2.?3 week|december|postpon|catalog|instagram|closer|assist|option|date|2026|2027|when|move|long|срок|period/i,
      /reserv|week|closer|catalog|instagram|thank|assist|month|option|postpon/i,
      /november|week|reserv|option|available|sorry|understand|check|now|december|type|budget|studio|bedroom/i,
    ],
  },
  {
    scenario: 'operator_handoff',
    turns: [
      'Hi, I need a studio in JLT budget 9000 move in 20 November for 6 months',
      'I want to speak with a human operator',
    ],
    expect: [
      /studio|jlt|option|link|aed|move|confirm|found|budget|colife/i,
      /specialist|operator|connect|подключа|менеджер|summary|handoff/i,
    ],
    crmExpect: {
      requireDeal: true,
      tools: ['set_wikibot_in_chat_status'],
      chatStatus: /connected|disconnected/i,
    },
  },
  {
    scenario: 'sharing_business_bay',
    turns: [
      'Business bay nearest to metropolis tower\n21 November',
      'I room big in an apartment\nNo bed space\nNot\nA whole rooom big',
      '2000 2 months',
      'Okay',
    ],
    expect: [
      /duration|how long|budget|studio|bedroom|room|month|rent|type/i,
      /budget|month|how long|duration|rent|aed|room/i,
      /specialist|operator|transfer|connect|подключа|менеджер/i,
      /summary|specialist|operator|room|business bay|2000|result|follow|^$/i,
    ],
    crmExpect: {
      requireDeal: true,
      tools: ['set_wikibot_in_chat_status', 'qualify_lead'],
      category: /sharing|out of budget|target|supertarget/i,
      chatStatus: /connected|disconnected/i,
      apartmentType: /room|bedspace|studio|no information/i,
    },
  },
  {
    scenario: 'bayut_48_budget_flex',
    turns: [
      'Hello, my name is Elizabeth, I am Colife Dubai manager!\n\nWe have received your apartment request from Bayut for unit 48.1\n\nPlease confirm us if we should keep your request open and continue processing it. | [Yes] | [No, thanks]',
      'Yes',
      '1. 15th nov-15th December\n2. 1 month firstly\n3. 1 person\nInitially',
      'Budget wise 6500 , in parellel\nI will search long term for rent or purchase',
      '8999 is bit high i can till 6500 is any option u have?',
      '8999 no',
      'I am open for albarsha/JLT as wll',
    ],
    expect: [
      /48\.1|unit|available|marina|liv|confirm|move|when|date|open|^$/i,
      /move|when|date|заезд|long|how long|budget|available|link|marina|type|booked|alternative/i,
      /budget|aed|month|price|type|studio|bedroom/i,
      /option|link|studio|marina|aed|budget|yacht|8899|8999|found|consider|specialist|operator|type/i,
      /dewa|wifi|include|save|consider|option|link|budget|studio|aed|alternative|^$/i,
      /option|link|studio|jabal|jlt|barsha|marina|aed|area|consider|catalog|^$/i,
      /jlt|barsha|marina|option|link|catalog|aed|studio|available|no suitable|keep an eye|specialist|operator|^$/i,
    ],
  },
  {
    scenario: 'bayut_577_urgent_areas',
    turns: [
      'Hello, my name is Elizabeth, I am Colife Dubai manager!\n\nWe have received your apartment request from Bayut for unit 577.1\n\nPlease confirm us if we should keep your request open and continue processing it. | [Yes] | [No, thanks]',
      'Yes',
      'One month\nPossibly for longer',
      'Tomorrow\nOr Thursday',
      'No more than 8 k',
      'Azizi , business bay and barsha heights , marina',
      '?',
    ],
    expect: [
      /577|unit|available|confirm|move|^$/i,
      /577|booked|alternative|move|when|long|how long|rent/i,
      /when|move|date|заезд|tomorrow|check.?in|type|budget/i,
      /budget|type|area|apartment|prefer|aed|urgent|specialist|operator/i,
      /type|area|prefer|studio|bedroom|option|link|aed|specialist|operator|transfer|connect/i,
      /specialist|operator|connect|подключа|option|link|marina|bay|barsha|^$/i,
      /summary|specialist|operator|^$/i,
    ],
  },
  {
    scenario: 'bayut_794_weekly_short_term',
    turns: [
      'Hello, my name is Elizabeth, I am Colife Dubai manager!\n\nWe have received your apartment request from Bayut for unit 794.1\n\nPlease confirm us if we should keep your request open and continue processing it. | [Yes] | [No, thanks]',
      'Yes',
      'Immediate , for 1 month or do you have weekly option',
      'Is there an option for less than a month ?',
      'I request you to address my question first so we conclude this in minimal messages',
    ],
    expect: [
      /794|unit|available|confirm|move|^$/i,
      /move|when|date|заезд|long|how long|week|month|available|link/i,
      /week|short|month|minimum|term|specialist|operator|option/i,
      /week|short|month|minimum|specialist|operator|connect|подключа/i,
      /specialist|operator|connect|подключа|summary|week|short|^$/i,
    ],
  },
  {
    scenario: 'pf_794_cost_breakdown',
    turns: [
      'Hello, my name is Elizabeth, I am Colife Dubai manager!\n\nWe have received your apartment request from Propertyfinder for unit 794.1\n\nPlease confirm us if we should keep your request open and continue processing it. | [Yes] | [No, thanks] Yes',
      'As soon as possible. Move in for one month initially, but i could extend. Also depends if there is a discount for long-term stay.',
      'Could you give me a breakdown of the total costs for a one month stay?',
      'Up to 8500 excluding additional fees',
      'On the ad it says 8,074 monthly',
    ],
    expect: [
      /794|unit|available|confirm|move|when|date|booked|alternative|^$/i,
      /month|discount|long|term|price|aed|include|dewa|wifi|fee|budget|area|booked|alternative|asap|soon/i,
      /cost|fee|include|dewa|wifi|deposit|total|aed|breakdown|price|specialist|operator/i,
      /specialist|operator|connect|подключа|urgent|8500|budget|studio|bedroom|type|^$/i,
      /8074|ad|price|fee|include|specialist|operator|confirm|aed|studio|bedroom|type|^$/i,
    ],
  },
  {
    scenario: 'returning_room_downtown',
    turns: [
      'Hi',
      'This is Caroline, you asked me to let you when I will arrive in Dubai.',
      'I will arrive friday and I like your offer something about 2000dirham Friday',
      '3000',
      'You offered me last time ca. 2 weeks ago a nice room for 2999 Dirham',
      'sorry I dont have it anymore is there any nice offer for 4000?',
      'maybe 2 months, but for sure 1 month downtown',
    ],
    expect: [
      /colife|help|hi|здравству|arrive|dubai/i,
      /arrive|when|date|friday|room|offer|thank/i,
      /budget|room|aed|2000|option|downtown|friday|move|specialist|operator|4999|sharing/i,
      /3000|budget|room|option|link|aed|downtown|specialist|operator|^$/i,
      /2999|room|option|available|sorry|alternative|aed|^$/i,
      /4000|room|option|link|aed|downtown|available|found|^$/i,
      /downtown|month|room|option|link|aed|specialist|operator|^$/i,
    ],
  },
];

/**
 * @param {string} dialogId
 * @param {string} text
 * @param {number} n
 */
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

/**
 * @param {E2eScenario} item
 * @param {object} state
 * @param {object|null} dealFields
 * @returns {{ ok: boolean, checks: object[] }}
 */
function verifyCrm(item, state, dealFields) {
  const checks = [];
  const exp = item.crmExpect;
  if (!exp) return { ok: true, checks };

  const writes = state.extras?.crmWrites || [];
  const toolsCalled = writes.map((w) => w.tool);

  if (exp.requireDeal) {
    const ok = Boolean(state.dealId);
    checks.push({ name: 'dealId', ok, got: state.dealId || null });
  }
  if (exp.tools?.length) {
    for (const tool of exp.tools) {
      const ok = toolsCalled.includes(tool);
      checks.push({ name: `tool:${tool}`, ok, got: toolsCalled });
    }
    const failedWrite = writes.find((w) => w.ok === false);
    checks.push({
      name: 'crm_writes_ok',
      ok: !failedWrite,
      got: failedWrite || 'all_ok',
    });
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
    const live = dealFields?.budget || '';
    checks.push({ name: 'budget', ok: exp.budget.test(String(live)), got: live });
  }
  if (exp.moveInDate) {
    const live = dealFields?.moveInDate || state.savedMoveInDate || '';
    const ok = typeof exp.moveInDate === 'boolean'
      ? Boolean(live)
      : exp.moveInDate.test(String(live));
    checks.push({ name: 'moveInDate', ok, got: live });
  }

  return { ok: checks.every((c) => c.ok), checks };
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
  const dialogId = `e2e-${item.scenario}-${Date.now()}`;
  const state = worker.store.get(dialogId);

  let dealId = null;
  if (crmEnabled()) {
    dealId = await createDeal('Test AI');
    state.dealId = dealId;
    console.log(`[${item.scenario}] CRM deal #${dealId}`);
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
    const ok = expect ? expect.test(reply) : Boolean(reply.trim());
    turns.push({
      n: i + 1,
      user: userText,
      reply,
      ms: Date.now() - started,
      ok,
      expect: expect ? String(expect) : null,
      handedOff,
    });
    console.log(`\n[${item.scenario}] #${i + 1} ${ok ? 'PASS' : 'FAIL'} (${Date.now() - started}ms)`);
    console.log(`USER: ${userText}`);
    console.log(`BOT: ${reply.slice(0, 500)}${reply.length > 500 ? '…' : ''}`);
    if (!ok) {
      console.log(`EXPECT: ${expect}`);
    }
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

  setVibeTestHooks(null);
  const passed = turns.every((t) => t.ok) && crm.ok;
  return {
    scenario: item.scenario,
    passed,
    handedOff,
    turns,
    dealId: state.dealId || dealId,
    category: state.category,
    crmWrites: state.extras?.crmWrites || [],
    dealFields,
    crmChecks: crm.checks,
  };
}

/**
 * @param {string[]} argv
 * @returns {{ only: string|null, count: number }}
 */
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
  const base = only ? scenarios.filter((s) => s.scenario === only) : scenarios;
  if (!base.length) {
    console.error('No scenarios matched');
    process.exit(1);
  }
  const target = count > 0 ? count : base.length;
  const queue = [];
  for (let i = 0; i < target; i += 1) {
    queue.push(base[i % base.length]);
  }

  const results = [];
  for (let i = 0; i < queue.length; i += 1) {
    const item = queue[i];
    const round = Math.floor(i / base.length) + 1;
    const label = count > 0 ? `${item.scenario} [${i + 1}/${target} r${round}]` : item.scenario;
    console.log(`\n======== ${label} ========`);
    const result = await runScenario(item);
    results.push({ ...result, runIndex: i + 1, round });
    const passN = results.filter((r) => r.passed).length;
    console.log(`[progress] ${i + 1}/${target} done · pass ${passN} · fail ${i + 1 - passN}`);
  }
  const outPath = path.join(root, 'data/state/e2e-last.json');
  const batchPath = path.join(root, 'data/state', `e2e-batch-${new Date().toISOString().replace(/[:.]/g, '-')}.json`);
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  const payload = { at: new Date().toISOString(), target, results };
  fs.writeFileSync(outPath, JSON.stringify(payload, null, 2));
  fs.writeFileSync(batchPath, JSON.stringify(payload, null, 2));
  const failed = results.filter((r) => !r.passed);
  console.log('\n======== SUMMARY ========');
  console.log(`CRM: ${crmEnabled() ? 'enabled' : 'disabled'}`);
  console.log(`chats: ${results.length} · pass ${results.length - failed.length} · fail ${failed.length}`);
  for (const r of results) {
    const crmPart = r.crmChecks?.length
      ? ` crm ${r.crmChecks.filter((c) => c.ok).length}/${r.crmChecks.length}`
      : '';
    console.log(`${r.passed ? 'PASS' : 'FAIL'} #${r.runIndex} ${r.scenario} (${r.turns.filter((t) => t.ok).length}/${r.turns.length}${crmPart}) deal=${r.dealId || '—'}`);
  }
  console.log(`saved ${outPath}`);
  console.log(`saved ${batchPath}`);
  process.exit(failed.length ? 1 : 0);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
