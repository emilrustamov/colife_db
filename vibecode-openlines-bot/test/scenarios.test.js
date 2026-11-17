import test from 'node:test';
import assert from 'node:assert/strict';
import { createDialogState } from '../lib/state.js';
import {
  activateScenario,
  enrichStateForLeave,
  hydrateStateFromText,
  isPostponedMoveIn,
  languageSourceText,
  looksLikeRoomRequest,
  replyLanguageMismatch,
  stayDurationFromPeriod,
  stripReIntro,
} from '../lib/scenarios.js';
import { executeTool } from '../lib/tools.js';
import { unitFitsMoveIn } from '../lib/searchApartment.js';

test('room request ignores N-bedroom apartments', () => {
  assert.equal(looksLikeRoomRequest('I want a master-room from 20 november'), true);
  assert.equal(
    looksLikeRoomRequest(
      'looking for a room with a view so 1 bedroom I have a dog',
    ),
    false,
  );
  assert.equal(looksLikeRoomRequest('2 bedroom in marina'), false);
});

test('weekly stay maps to 1-14 days', () => {
  assert.equal(stayDurationFromPeriod(0.5), '1-14_days');
  assert.equal(stayDurationFromPeriod(0.75), '15-29_days');
});

test('hydrate overwrites move-in and type from latest utterance', () => {
  const state = createDialogState();
  hydrateStateFromText(state, '1 bedroom next week');
  assert.deepEqual(state.types, [1]);
  assert.equal(state.apartmentType, '1_bedroom');
  assert.ok(state.routingMoveInDate);

  hydrateStateFromText(state, '2 bedroom for 3 months');
  assert.deepEqual(state.types, [2]);
  assert.equal(state.apartmentType, '2_bedroom');
  assert.equal(state.period, 3);
});

test('non_target scenario sets category for CRM leave snapshot', () => {
  const state = createDialogState();
  activateScenario(state, 'non_target');
  assert.equal(state.category, 'non_target');
  enrichStateForLeave(state);
  assert.equal(state.extras.leaveSnapshot.category, 'non_target');
});

test('language source ignores robot RU wrapper', () => {
  const robot =
    '[B]Отправлено роботом[/B] Hello, my name is Elizabeth, I am Colife Dubai manager! We have received your apartment request from Bayut for unit 7.1 Please confirm us if we should keep your request open and continue processing it. | [Yes] | [No, thanks]';
  assert.equal(languageSourceText(robot), null);
  assert.equal(languageSourceText(`${robot} Yes`), 'Yes');
  assert.equal(languageSourceText('english please'), 'english please');
});

test('leave snapshot keeps room when sharing without types', () => {
  const state = createDialogState();
  hydrateStateFromText(state, 'master-room next week 4k');
  enrichStateForLeave(state);
  assert.equal(state.activeScenario, 'sharing');
  assert.equal(state.extras.leaveSnapshot.apartment_type, 'room');
});

test('stale types do not overwrite sharing room on leave', () => {
  const state = createDialogState();
  state.types = [1];
  state.apartmentType = 'room';
  state.activeScenario = 'sharing';
  state.category = 'sharing';
  enrichStateForLeave(state);
  assert.equal(state.extras.leaveSnapshot.apartment_type, 'room');
});

test('sharing lead cannot search or get apartment min_price', async () => {
  const state = createDialogState();
  activateScenario(state, 'sharing');
  state.apartmentType = 'room';
  const runtime = { pendingAnswers: [] };
  const search = await executeTool(state, 'search_apartments', { area: ['JLT'], price: 4000 }, runtime);
  assert.equal(search.error, 'sharing_no_catalog');
  const min = await executeTool(state, 'get_min_price', { types: [0] }, runtime);
  assert.equal(min.error, 'sharing_no_catalog');
});

test('postponed threshold and unit availability fit', () => {
  assert.equal(isPostponedMoveIn('15-12-2026'), true);
  assert.equal(isPostponedMoveIn('09-12-2026'), false);
  assert.equal(unitFitsMoveIn('2026-11-30', '23-11-2026').fits, false);
  assert.equal(unitFitsMoveIn('2026-11-20', '23-11-2026').fits, true);
  assert.equal(unitFitsMoveIn('', '23-11-2026').known, false);
});

test('reply language mismatch and re-intro strip', () => {
  assert.equal(
    replyLanguageMismatch('Здравствуйте! Это менеджер Colife, подскажите дату заезда пожалуйста.', 'en'),
    true,
  );
  assert.equal(replyLanguageMismatch('Could you share your move-in date?', 'en'), false);
  assert.match(
    stripReIntro('Hello! This is Colife manager.\nWhat is your budget?', true),
    /budget/i,
  );
});
