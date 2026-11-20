import test from 'node:test';
import assert from 'node:assert/strict';
import { createDialogState } from '../lib/state.js';
import { handoffSearchGate, executeTool } from '../lib/tools.js';
import { hydrateStateFromText } from '../lib/scenarios.js';
import { setApartmentsCache } from '../lib/catalog.js';

const sample = [
  {
    uid: '1',
    apartmentId: 1,
    title: 'Studio, Test, JVC',
    link: 'https://colife.ae/catalog/tproduct/1',
    area: 'JVC',
    type: 'Studio',
    rentType: 'R2R',
    category: 'A',
    priceUnit: 'month',
    price: 6599,
    priceVariants: [{ period: '1 month', price: '6599' }],
    text: 'Studio in JVC',
  },
];

test('gate blocks leave before any search', () => {
  const state = createDialogState();
  state.budget = 7000;
  state.period = 1;
  const gated = handoffSearchGate(state);
  assert.equal(gated?.error, 'search_required');
});

test('gate allows terminal scenarios without search', () => {
  const state = createDialogState();
  state.activeScenario = 'postponed';
  assert.equal(handoffSearchGate(state), null);
});

test('gate blocks after first not_found until flex + retry', async () => {
  setApartmentsCache(sample);
  const state = createDialogState();
  const runtime = { pendingAnswers: [], pendingHandoff: null };

  const miss = await executeTool(
    state,
    'search_apartments',
    { area: ['JVC'], types: [0], price: 1000, period: 1 },
    runtime,
  );
  assert.equal(miss.error, 'not_found');
  assert.equal(state.searchAttempts, 1);
  assert.equal(state.extras.lastSearchNotFound, true);

  assert.equal(handoffSearchGate(state)?.error, 'flex_required');

  const leaveBlocked = await executeTool(state, 'leave_chat', { reason: 'early' }, runtime);
  assert.equal(leaveBlocked.blocked, true);

  const notifyBlocked = await executeTool(
    state,
    'make_answer',
    { text: 'Connecting you to a specialist now.' },
    runtime,
  );
  assert.equal(notifyBlocked.blocked, true);
  assert.deepEqual(runtime.pendingAnswers, []);

  hydrateStateFromText(state, 'ok I can do 7000');
  assert.equal(state.extras.flexApplied, true);
  assert.equal(handoffSearchGate(state)?.error, 'search_retry_required');

  const retry = await executeTool(
    state,
    'search_apartments',
    { area: ['JVC'], types: [0], price: 7000, period: 1 },
    runtime,
  );
  assert.ok(retry.apartments?.length || retry.error === 'not_found');
  assert.equal(state.searchAttempts, 2);
  assert.equal(handoffSearchGate(state), null);
});

test('gate allows leave when offers were already shown', () => {
  const state = createDialogState();
  state.searchAttempts = 0;
  state.shownLinks = ['https://colife.ae/catalog/tproduct/1'];
  assert.equal(handoffSearchGate(state), null);
});
