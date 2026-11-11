import test from 'node:test';
import assert from 'node:assert/strict';
import { sanitizeClientText } from '../lib/scenarios.js';

test('formats apartment list with newlines and BBCode links', () => {
  const raw =
    'Для 2-спальных апартаментов (2bd) сейчас есть следующие варианты, но они немного превышают ваш бюджет: 1. [2br Apartment, The V Tower, Wadi Al Safa 5](https://colife.ae/catalog/tproduct/605208928-533217317022-2br-apartment-the-v-tower-wadi-al-safa-5) — 10 699 AED в месяц. 2. [2br Apartment, Golfville Block A, Dubai Hills](https://colife.ae/catalog/tproduct/605208928-297570960622-2br-apartment-golfville-block-a-dubai-hi) — 12 999 AED в месяц. Рассматриваете ли вы варианты чуть дороже вашего бюджета или мне поискать что-то другое?';
  const out = sanitizeClientText(raw);
  assert.match(out, /\[url=https:\/\/colife\.ae\/catalog\/tproduct\/605208928-533217317022-2br-apartment-the-v-tower-wadi-al-safa-5\]/);
  assert.match(out, /\n1\. /);
  assert.match(out, /\n2\. /);
  assert.match(out, /\n\nРассматриваете/);
  assert.equal(out.includes(']('), false);
});
