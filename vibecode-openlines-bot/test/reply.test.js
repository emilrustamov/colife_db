import test from 'node:test';
import assert from 'node:assert/strict';
import {
  parseModelReply,
  shouldHandleMessageEvent,
  wantsOperator,
} from '../lib/reply.js';

test('routes customer message and skips system/own', () => {
  assert.equal(
    shouldHandleMessageEvent({
      type: 'ONIMBOTV2MESSAGEADD',
      data: {
        bot: { id: 10 },
        message: { text: 'hello', isSystem: false, authorId: 5 },
        chat: { dialogId: 'chat1' },
      },
    }),
    true,
  );
  assert.equal(
    shouldHandleMessageEvent({
      type: 'ONIMBOTV2MESSAGEADD',
      data: {
        bot: { id: 10 },
        message: { text: 'x', isSystem: true, authorId: 5 },
      },
    }),
    false,
  );
  assert.equal(
    shouldHandleMessageEvent({
      type: 'ONIMBOTV2MESSAGEADD',
      data: {
        bot: { id: 10 },
        message: { text: 'x', isSystem: false, authorId: 10 },
      },
    }),
    false,
  );
});

test('operator request detection', () => {
  assert.equal(wantsOperator('/operator'), true);
  assert.equal(wantsOperator('нужен оператор'), true);
  assert.equal(wantsOperator('call a human please'), true);
  assert.equal(wantsOperator('how much is rent'), false);
});

test('model reply parsing and outside-kb handoff', () => {
  assert.deepEqual(
    parseModelReply('{"answer":"Rent is fixed in contract","handoff":false}'),
    { answer: 'Rent is fixed in contract', handoff: false },
  );
  assert.equal(parseModelReply('{"answer":"","handoff":true}').handoff, true);
  assert.equal(parseModelReply('HANDOFF').handoff, true);
});
