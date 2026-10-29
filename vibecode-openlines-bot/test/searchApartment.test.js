import test from 'node:test';
import assert from 'node:assert/strict';
import {
  classifyBudgetTarget,
  extractDaysToCheckin,
  extractSearchArgs,
  searchApartment,
  searchApartments,
  shouldClassifyBudgetTarget,
} from '../lib/searchApartment.js';

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
  {
    uid: '2',
    apartmentId: 2,
    title: 'Studio, Other, JVC',
    link: 'https://colife.ae/catalog/tproduct/2',
    area: 'JVC',
    type: 'Studio',
    rentType: 'R2R',
    category: 'A',
    priceUnit: 'month',
    price: 6899,
    priceVariants: [{ period: '1 month', price: '6899' }],
    text: 'Studio 2',
  },
  {
    uid: '3',
    apartmentId: 3,
    title: '1br Apartment, Hamilton Residency, Business Bay',
    link: 'https://colife.ae/longterm/ru/tproduct/3',
    area: 'Business Bay',
    type: '1br Apartment',
    rentType: 'EJARI',
    category: 'L',
    priceUnit: 'year',
    price: 80000,
    priceVariants: [{ period: '12 months', price: '80000' }],
    text: 'Hamilton',
  },
];

test('extracts area/type/price', () => {
  const args = extractSearchArgs('Looking for studio in JVC under 7000 AED');
  assert.deepEqual(args.area, ['JVC']);
  assert.deepEqual(args.types, [0]);
  assert.equal(args.price, 7000);
});

test('searchApartments returns up to 3 and supports exclude_ids', () => {
  const first = searchApartments(
    { area: ['JVC'], types: [0], price: 7000, exclude_ids: [] },
    sample,
  );
  assert.equal(first.error, undefined);
  assert.ok((first.apartments || []).length >= 1);
  assert.equal(first.apartments[0].apartment.link, 'https://colife.ae/catalog/tproduct/1');

  const second = searchApartments(
    { area: ['JVC'], types: [0], price: 7000, exclude_ids: [1] },
    sample,
  );
  assert.equal(second.apartments[0].apartment.apartmentId, 2);
});

test('search can find yearly longterm by area/type', () => {
  const result = searchApartment(
    { area: 'Business Bay', type: 1, price: -1, period: 12 },
    sample,
  );
  assert.match(result.apartment.title, /Hamilton/);
});

test('not_found with min price instruction', () => {
  const result = searchApartments(
    { area: ['JVC'], types: [0], price: 1000, exclude_ids: [] },
    sample,
  );
  assert.equal(result.error, 'not_found');
  assert.match(result.agentInstruction || '', /дороже|оператора|повыше/i);
});

test('extracts days to checkin and unlimited budget', () => {
  assert.equal(extractDaysToCheckin('заезд через 10 дней'), 10);
  assert.equal(extractDaysToCheckin('check-in in 2 weeks'), 14);
  assert.equal(extractSearchArgs('бюджет не ограничен').price, 99999);
});

test('parses informal RU lead with budget range and Dec dates', () => {
  const msg =
    'Добрый день! Я иду квартиру 7-9 000 дерхам на двоих на месяц, с 8-15 декабря по 8-15 января, может и дольше 2 человека';
  const args = extractSearchArgs(msg);
  assert.equal(args.price, 9000);
  assert.equal(args.period, 1);
  assert.equal(args.guests, 2);
  assert.deepEqual(args.types || [], []);
  assert.ok(args.days_to_checkin != null && args.days_to_checkin > 0);
  assert.ok(extractDaysToCheckin(msg) != null);
  const follow = extractSearchArgs('район бз разницы, студия');
  assert.equal(follow.areaFlexible, true);
  assert.deepEqual(follow.types, [0]);
});

test('classify_budget_target target vs out_of_budget', () => {
  assert.equal(
    shouldClassifyBudgetTarget({ price: 7000, days_to_checkin: 20 }),
    true,
  );
  assert.equal(
    shouldClassifyBudgetTarget({ price: 7000, days_to_checkin: 90 }),
    false,
  );

  const ok = classifyBudgetTarget(
    { area: ['JVC'], types: [0], price: 7000, days_to_checkin: 20, exclude_ids: [] },
    sample,
  );
  assert.equal(ok.category, 'supertarget');
  assert.match(ok.agentInstruction, /qualify_lead|target|supertarget/i);

  const target = classifyBudgetTarget(
    { area: ['JVC'], types: [0], price: 7000, days_to_checkin: 45, exclude_ids: [] },
    sample,
  );
  assert.equal(target.category, 'target');

  const oob = classifyBudgetTarget(
    { area: ['JVC'], types: [0], price: 1000, days_to_checkin: 10, exclude_ids: [] },
    sample,
  );
  assert.equal(oob.category, 'out_of_budget');
});
