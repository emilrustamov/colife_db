import test from 'node:test';
import assert from 'node:assert/strict';
import { createDialogState } from '../lib/state.js';
import { detectLang } from '../lib/reply.js';
import {
  activateScenario,
  enrichStateForLeave,
  hydrateStateFromText,
  isRobotOnlyMessage,
  languageSourceText,
} from '../lib/scenarios.js';

const robot = (unit) =>
  `[B]Отправлено роботом[/B] Hello, my name is Elizabeth, I am Colife Dubai manager! We have received your apartment request from Bayut for unit ${unit} Please confirm us if we should keep your request open and continue processing it. | [Yes] | [No, thanks]`;

/**
 * @param {string[]} turns
 */
function run(turns) {
  const state = createDialogState();
  for (const text of turns) {
    const langText = languageSourceText(text);
    if (langText) state.language = detectLang(langText);
    else if (!state.language) state.language = 'en';
    if (!isRobotOnlyMessage(text)) hydrateStateFromText(state, text);
  }
  enrichStateForLeave(state);
  return state;
}

test('feedback #1 ejari fills move-in/budget/stay, english', () => {
  const s = run([`${robot('x')} Yes`, 'for a year , next week', '1br , 15k']);
  assert.equal(s.language, 'en');
  assert.equal(s.extras.days_to_checkin, 7);
  assert.equal(s.budget, 15000);
  assert.equal(s.extras.leaveSnapshot.stay_duration, '9-12_months');
  assert.equal(s.extras.leaveSnapshot.move_in, s.routingMoveInDate);
});

test('feedback #2/#3 non_target writes category', () => {
  const s = createDialogState();
  activateScenario(s, 'non_target');
  enrichStateForLeave(s);
  assert.equal(s.category, 'non_target');
  assert.equal(s.extras.leaveSnapshot.category, 'non_target');
});

test('leave without category becomes no_information', () => {
  const s = createDialogState();
  enrichStateForLeave(s);
  assert.equal(s.category, 'no_information');
  assert.equal(s.extras.leaveSnapshot.category, 'no_information');
});

test('feedback #4 out_of_budget language and fields', () => {
  const s = run([
    robot('1037.1'),
    'Yes',
    'english pls',
    'Want to start contract by 22 nov and 5000 max budget for 4 months monthly payement',
  ]);
  assert.equal(s.language, 'en');
  assert.equal(s.routingMoveInDate, '22-11-2026');
  assert.equal(s.budget, 5000);
});

test('feedback #5 postponed date not poisoned by unit 7.1', () => {
  const s = run([`${robot('7.1')} Yes`, '15 декабря']);
  assert.equal(s.routingMoveInDate, '15-12-2026');
  assert.equal(s.activeScenario, 'postponed');
});

test('feedback #6 dec 29 not year 2027', () => {
  const s = run([`${robot('990.1')} Yes`, 'english please 29 dec', 'for 1 month']);
  assert.equal(s.routingMoveInDate, '29-12-2026');
  assert.equal(s.extras.leaveSnapshot.stay_duration, '1_month');
});

test('feedback #7 nov 23 not poisoned by unit 853.1', () => {
  const s = run([
    `${robot('853.1')} Yes`,
    '23 november',
    'english 3 month',
    'for 6 month what price?',
  ]);
  assert.equal(s.routingMoveInDate, '23-11-2026');
  assert.equal(s.period, 6);
  assert.equal(s.language, 'en');
});

test('feedback #8 sharing master-room', () => {
  const s = run([
    'Dubai',
    'I want to rent master-room from 20 november',
    '3 month and 4k',
  ]);
  assert.equal(s.activeScenario, 'sharing');
  assert.equal(s.apartmentType, 'room');
  assert.equal(s.budget, 4000);
  assert.equal(s.routingMoveInDate, '20-11-2026');
});

test('feedback #9 short-term weekly stay 1-14 days', () => {
  const s = run(['Hi', 'Do you have any weekly rental options in Azizi riveira']);
  assert.equal(s.category, 'short_term');
  assert.equal(s.extras.leaveSnapshot.stay_duration, '1-14_days');
});

test('feedback #10 today not poisoned by unit 107.1', () => {
  const s = run([
    robot('107.1'),
    'Yes',
    'Hello',
    'want to move in today',
    'year 15k 1br',
  ]);
  assert.equal(s.extras.days_to_checkin, 0);
  assert.equal(s.planningToMove, 'urgent');
  assert.equal(s.activeScenario, 'urgent_move_in');
  assert.equal(s.budget, 15000);
  assert.notEqual(s.routingMoveInDate, '07-01-2027');
});

test('feedback #11 1 bedroom is not sharing', () => {
  const s = run([
    "Hey, I'm looking for an apartment for 3 months in downtown",
    "7 december I'm looking for a room with a view so 1 bedroom I have a dog",
    '13k AED Maximum',
  ]);
  assert.notEqual(s.activeScenario, 'sharing');
  assert.equal(s.apartmentType, '1_bedroom');
  assert.equal(s.budget, 13000);
  assert.equal(s.routingMoveInDate, '07-12-2026');
});

test('feedback #12 2br and 2-3 month does not rewrite move-in', () => {
  const s = run([
    `${robot('32.1')} Yes`,
    'i want to move in on 19 november',
    'please is there an option with the same price range in marina for 2 bedroom?',
    '2-3 month 18000',
  ]);
  assert.equal(s.apartmentType, '2_bedroom');
  assert.equal(s.routingMoveInDate, '19-11-2026');
  assert.equal(s.budget, 18000);
  assert.equal(s.period, 3);
});

test('feedback #13 immediate 4000 is urgent', () => {
  const s = run(['Need to move immediately 4000']);
  assert.equal(s.budget, 4000);
  assert.equal(s.extras.days_to_checkin, 0);
  assert.equal(s.activeScenario, 'urgent_move_in');
});

test('asap and immediately share urgent bucket (0 days)', () => {
  const asap = run(['Need to move asap 5000']);
  assert.equal(asap.extras.days_to_checkin, 0);
  assert.equal(asap.planningToMove, 'urgent');
  assert.equal(asap.activeScenario, 'urgent_move_in');
});

test('move-in in N days is not short_term stay', () => {
  const s = run(['Looking for a studio, check-in in 10 days, budget 8k']);
  assert.notEqual(s.activeScenario, 'short_term');
  assert.notEqual(s.category, 'short_term');
  assert.equal(s.extras.days_to_checkin, 10);
  assert.ok(s.period == null || s.period >= 1);
});

test('sharing then 1 bedroom leave snapshot is not room', () => {
  const s = run([
    'I want a master-room from 20 november',
    'actually looking for 1 bedroom instead, 12k',
  ]);
  assert.notEqual(s.activeScenario, 'sharing');
  assert.equal(s.apartmentType, '1_bedroom');
  assert.equal(s.extras.leaveSnapshot.apartment_type, '1_bedroom');
});
