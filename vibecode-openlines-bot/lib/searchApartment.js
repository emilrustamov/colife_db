import olBotConfig from '../config/ol-bot.js';
import { loadApartments } from './catalog.js';
import { matchAreasInText } from './handbook.js';
import { typeToLabel } from './types.js';

const MAX_RESULT_SIZE = olBotConfig.search.maxResults;
const SUPER_TARGET_DAYS = olBotConfig.classify.superTargetDays;
const CLASSIFY_MAX_DAYS = olBotConfig.classify.maxDays;
const UNLIMITED_BUDGET = olBotConfig.classify.unlimitedBudget;
const PRICE_FLEX = olBotConfig.search.priceFlex;
const PREFER_RENT_TYPE = olBotConfig.search.preferRentType;
const PREFER_CATEGORY = olBotConfig.search.preferCategory;

/**
 * @typedef {object} SearchApartmentsArgs
 * @property {string[]} [area]
 * @property {number} [price]
 * @property {number[]} [types]
 * @property {number} [period]
 * @property {number[]} [exclude_ids]
 * @property {number} [days_to_checkin]
 * @property {string} [move_in_date]
 * @property {boolean} [areaFlexible]
 * @property {number} [guests]
 */

const MONTH_ALIASES = [
  ['январ', 'jan'],
  ['феврал', 'feb'],
  ['март', 'mar'],
  ['апрел', 'apr'],
  ['ма', 'may'],
  ['июн', 'jun'],
  ['июл', 'jul'],
  ['август', 'aug'],
  ['сентябр', 'sep'],
  ['октябр', 'oct'],
  ['ноябр', 'nov'],
  ['декабр', 'dec'],
];

/**
 * @param {string} mon
 * @returns {number}
 */
function monthIndexFromName(mon) {
  const m = String(mon || '').toLowerCase();
  return MONTH_ALIASES.findIndex((aliases) => aliases.some((a) => m.startsWith(a)));
}

/**
 * @param {number} day
 * @param {number} month
 * @param {number} [year]
 * @returns {number|undefined}
 */
function daysUntilDate(day, month, year) {
  if (!Number.isFinite(day) || !Number.isFinite(month) || month < 0) return undefined;
  const now = new Date();
  let y = year != null ? year : now.getFullYear();
  const target = new Date(y, month, day);
  if (target < now) target.setFullYear(y + 1);
  const diff = Math.ceil((target.getTime() - now.getTime()) / 86400000);
  return Number.isFinite(diff) && diff >= 0 ? diff : undefined;
}

/**
 * @param {string|null|undefined} value
 * @returns {Date|null}
 */
/**
 * @param {string|null|undefined} value
 * @returns {Date|null}
 */
export function parseIsoDate(value) {
  const raw = String(value || '').trim();
  let m = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (m) {
    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    return Number.isNaN(d.getTime()) ? null : d;
  }
  m = raw.match(/^(\d{2})-(\d{2})-(\d{4})$/);
  if (m) {
    const d = new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1]));
    return Number.isNaN(d.getTime()) ? null : d;
  }
  return null;
}

/**
 * @param {string|null|undefined} availableFrom
 * @param {string|null|undefined} moveInDdMmYyyy
 * @returns {{ known: boolean, fits: boolean|null, availableFrom: string, moveIn: string|null }}
 */
export function unitFitsMoveIn(availableFrom, moveInDdMmYyyy) {
  const availableRaw = String(availableFrom || '').trim();
  const moveRaw = String(moveInDdMmYyyy || '').trim() || null;
  const available = parseIsoDate(availableRaw);
  const moveIn = parseIsoDate(moveRaw);
  if (!availableRaw || !available) {
    return { known: false, fits: null, availableFrom: availableRaw, moveIn: moveRaw };
  }
  if (!moveIn) {
    return { known: true, fits: null, availableFrom: availableRaw, moveIn: moveRaw };
  }
  return {
    known: true,
    fits: available.getTime() <= moveIn.getTime(),
    availableFrom: availableRaw,
    moveIn: moveRaw,
  };
}

/**
 * Keep listing only when availableFrom is known and <= move-in.
 *
 * @param {string|null|undefined} availableFrom
 * @param {Date} moveIn
 * @returns {boolean}
 */
function availableByMoveIn(availableFrom, moveIn) {
  const yyyy = moveIn.getFullYear();
  const mm = String(moveIn.getMonth() + 1).padStart(2, '0');
  const dd = String(moveIn.getDate()).padStart(2, '0');
  const fit = unitFitsMoveIn(availableFrom, `${yyyy}-${mm}-${dd}`);
  return fit.known && fit.fits === true;
}

// RU stems need [а-яё]* endings; EN forms must not match inside "month"/"months".
const MONTH_RU_RE = 'январ|феврал|март|апрел|ма[йя]|июн|июл|август|сентябр|октябр|ноябр|декабр';
const MONTH_EN_RE =
  'january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sep|oct|nov|dec';

/**
 * @param {string} text
 * @returns {number|undefined}
 */
export function extractDaysToCheckin(text) {
  // Unit SKUs (7.1) are not stripped here — two-part dotted tokens are simply
  // not accepted as dates (year required for dots; / or - for day/month only).
  const t = String(text || '').replace(/\bunit\s+\d+(?:[./-]\d+[a-zA-Z]?)*/gi, ' ');
  // Same urgency bucket: today / now / asap → 0 days (planning=urgent).
  if (/\b(today|сегодня|сейчас|immediately|немедленн|asap|urgent|as soon as)\b/i.test(t)) return 0;
  if (/\b(tomorrow|завтра)\b/i.test(t)) return 1;
  if (/\b(next\s+week|следующ\w*\s+недел)\b/i.test(t)) return 7;

  const daysMatch = t.match(
    /(\d+)\s*(?:day|days|дн(?:я|ей|ень)?|сутки|суток)/i,
  );
  if (daysMatch) return Number.parseInt(daysMatch[1], 10);

  const weeksMatch = t.match(/(\d+)\s*(?:week|weeks|недел)/i);
  if (weeksMatch) return Number.parseInt(weeksMatch[1], 10) * 7;

  const monthsMatch = t.match(
    /(\d+)\s*(?:month|months|мес(?:яц|яца|яцев)?)\s*(?:до\s*заезд|before\s*check|до\s*переезд)?/i,
  );
  if (monthsMatch && /заезд|check[\s-]?in|переезд|заселен/i.test(t)) {
    return Number.parseInt(monthsMatch[1], 10) * 30;
  }

  const throughMatch = t.match(
    /через\s+(\d+)\s*(?:день|дня|дней|недел|неделю|недели|месяц)/i,
  );
  if (throughMatch) {
    const n = Number.parseInt(throughMatch[1], 10);
    if (/недел/i.test(throughMatch[0])) return n * 7;
    if (/месяц/i.test(throughMatch[0])) return n * 30;
    return n;
  }

  const inMatch = t.match(/\bin\s+(\d+)\s*(days?|weeks?|months?)\b/i);
  if (inMatch) {
    const n = Number.parseInt(inMatch[1], 10);
    const unit = inMatch[2].toLowerCase();
    if (unit.startsWith('week')) return n * 7;
    if (unit.startsWith('month')) return n * 30;
    return n;
  }

  const rangeDayMonth = t.match(
    new RegExp(
      `(?:с|from)?\\s*(\\d{1,2})\\s*[-–—]\\s*(\\d{1,2})\\s*(?:(${MONTH_RU_RE})[а-яё]*|(${MONTH_EN_RE})\\b)`,
      'i',
    ),
  );
  if (rangeDayMonth) {
    const idx = monthIndexFromName(rangeDayMonth[3] || rangeDayMonth[4]);
    if (idx >= 0) return daysUntilDate(Number.parseInt(rangeDayMonth[1], 10), idx);
  }

  const singleDayMonthPatterns = [
    new RegExp(
      `(?:с|from|заезд|check[\\s-]?in|переезд)\\s*(?:с|from)?\\s*(\\d{1,2})\\s*(?:(${MONTH_RU_RE})[а-яё]*|(${MONTH_EN_RE})\\b)`,
      'i',
    ),
    // bare: 15 декабря / 15 December (no \\b before Cyrillic)
    new RegExp(
      `(?:^|[^0-9A-Za-zА-Яа-яЁё])(\\d{1,2})(?:st|nd|rd|th)?\\s*(?:(${MONTH_RU_RE})[а-яё]*|(${MONTH_EN_RE})\\b)`,
      'i',
    ),
  ];
  for (const re of singleDayMonthPatterns) {
    const m = t.match(re);
    if (!m) continue;
    const idx = monthIndexFromName(m[2] || m[3]);
    if (idx >= 0) return daysUntilDate(Number.parseInt(m[1], 10), idx);
  }

  const dateWithYear = t.match(/(\d{1,2})[./-](\d{1,2})[./-](\d{2,4})\b/);
  if (dateWithYear) {
    let year = Number.parseInt(dateWithYear[3], 10);
    if (year < 100) year += 2000;
    return daysUntilDate(
      Number.parseInt(dateWithYear[1], 10),
      Number.parseInt(dateWithYear[2], 10) - 1,
      year,
    );
  }

  // Two-part numeric dates only with / or - (dot is used by unit SKUs).
  // Skip period ranges like "2-3 month" / "1-2 weeks".
  const dateNoYear = t.match(
    /(\d{1,2})[/-](\d{1,2})(?![./-\d])(?!\s*(?:months?|мес|weeks?|days?|дн|years?|год))/i,
  );
  if (dateNoYear) {
    const day = Number.parseInt(dateNoYear[1], 10);
    const month = Number.parseInt(dateNoYear[2], 10) - 1;
    if (day >= 1 && day <= 31 && month >= 0 && month <= 11) {
      return daysUntilDate(day, month);
    }
  }

  if (/\b(friday|пятниц)/i.test(t)) {
    const now = new Date();
    const day = now.getDay();
    const delta = (5 - day + 7) % 7 || 7;
    return delta;
  }

  return undefined;
}

/**
 * @param {string} text
 * @returns {SearchApartmentsArgs}
 */
export function extractSearchArgs(text) {
  const t = String(text || '');

  /** @type {number[]} */
  const types = [];
  if (/\bstudio\b|студи/i.test(t)) types.push(0);
  if (/\b2\s*-?\s*br\b|\b2\s*bedroom\b|2[- ]?комнат|двухкомнат/i.test(t)) types.push(2);
  if (/\b1\s*-?\s*br\b|\b1\s*bedroom\b|1[- ]?комнат|однокомнат/i.test(t)) types.push(1);

  let price;
  if (
    /бюджет\s*не\s*(ограничен|важен|имеет\s*значения)|не\s*ограничен\s*бюджет|unlimited\s*budget|no\s*budget\s*limit|budget\s*(doesn'?t|does\s*not)\s*matter|any\s*budget/i.test(
      t,
    )
  ) {
    price = UNLIMITED_BUDGET;
  } else {
    const kMatch = t.match(/(\d+(?:[.,]\d+)?)\s*k\b/i);
    if (kMatch) {
      const n = Number.parseFloat(kMatch[1].replace(',', '.')) * 1000;
      if (Number.isFinite(n) && n >= 1000) price = Math.round(n);
    }
    if (price == null) {
      const rangeMatch = t.match(
        /(\d{1,2})\s*[-–—]\s*(\d{1,2})\s*([0-9]{3})\b|(\d{3,5})\s*[-–—]\s*(\d{3,5})\b/,
      );
      if (rangeMatch) {
        let hi;
        if (rangeMatch[1] && rangeMatch[3]) {
          hi = Number.parseInt(`${rangeMatch[2]}${rangeMatch[3]}`, 10);
        } else {
          hi = Number.parseInt(rangeMatch[5], 10);
        }
        if (Number.isFinite(hi) && hi >= 1000) price = hi;
      }
    }
    if (price == null) {
      // Prefer amounts marked as money; skip bare day numbers like "22 nov".
      const moneyRe =
        /(\d{1,3}(?:[\s,]?\d{3})+|\d{4,6}|\d+)\s*(?:aed|дирхам|дерхам|дирхем|бюджет|budget|max|under|до)?/gi;
      let m;
      while ((m = moneyRe.exec(t)) != null) {
        const n = Number.parseInt(m[1].replace(/[\s,]/g, ''), 10);
        if (!Number.isFinite(n) || n < 1000) continue;
        const marked = /aed|дирхам|дерхам|дирхем|бюджет|budget|max|under|до/i.test(m[0]);
        if (marked || n >= 1000) {
          price = n;
          if (marked) break;
        }
      }
    }
  }

  let period;
  const periodMatch = t.match(/(\d+)\s*(?:month|months|мес|месяц)/i);
  if (periodMatch) period = Number.parseInt(periodMatch[1], 10);
  else if (/на\s*месяц|for\s*a?\s*month|1\s*month|one\s*month|a\s*month/i.test(t)) period = 1;
  else if (/(?:two|2)\s*months?|(?:three|3)\s*months?/i.test(t)) {
    period = /\bthree\b|\b3\b/i.test(t) ? 3 : 2;
  } else if (/год|year|ejari/i.test(t)) period = 12;

  let guests;
  if (/на\s*двоих|2\s*(человек|чел|people|persons|guests)|двое|couple|for\s*two/i.test(t)) {
    guests = 2;
  } else {
    const guestsMatch = t.match(/(\d+)\s*(человек|чел|people|persons|guests)/i);
    if (guestsMatch) guests = Number.parseInt(guestsMatch[1], 10);
  }

  const areaFlexible =
    /б[еэ]?з\s*разниц|без\s*разницы|любой\s*район|район\s*не\s*важен|area\s*(doesn'?t|does\s*not)\s*matter|any\s*area|no\s*area\s*preference/i.test(
      t,
    );

  const daysToCheckin = extractDaysToCheckin(t);
  const area = matchAreasInText(t);

  return {
    area,
    ...(price != null ? { price } : {}),
    ...(types.length ? { types } : {}),
    ...(period != null ? { period } : {}),
    ...(daysToCheckin != null ? { days_to_checkin: daysToCheckin } : {}),
    ...(guests != null ? { guests } : {}),
    ...(areaFlexible ? { areaFlexible: true } : {}),
    exclude_ids: [],
  };
}

/**
 * @param {object} item
 * @returns {Array<{period:number, price:number}>}
 */
function convertPriceVariants(item) {
  const raw = item.priceVariants || [];
  const variants = raw
    .map((v) => {
      const periodParts = String(v.period || '').split(' ');
      return {
        period: Number.parseFloat(periodParts[0]) || (item.priceUnit === 'year' ? 12 : 1),
        price: Number.parseFloat(String(v.price || '').replace(/\s+/g, '')),
      };
    })
    .filter((v) => Number.isFinite(v.price));
  if (!variants.length && Number.isFinite(item.price)) {
    variants.push({
      period: item.priceUnit === 'year' ? 12 : 1,
      price: item.price,
    });
  }
  return variants;
}

/**
 * @param {Array<{period:number, price:number}>} priceVariants
 * @param {number|undefined} period
 * @returns {number|null}
 */
function getPriceToCheck(priceVariants, period) {
  if (!priceVariants.length) return null;
  if (period) {
    let latestPrice;
    for (const pv of priceVariants) {
      if (pv.period >= period) return pv.price;
      latestPrice = pv.price;
    }
    return latestPrice ?? null;
  }
  return Math.min(...priceVariants.map((item) => item.price));
}

/**
 * @param {SearchApartmentsArgs} args
 * @param {object[]} sourceApartments
 * @returns {{ matches: object[], apartmentsPool: object[], emptyCatalog: boolean }}
 */
function findMatchingApartments(args, sourceApartments) {
  if (!sourceApartments.length) {
    return { matches: [], apartmentsPool: [], emptyCatalog: true };
  }

  const excludeIds = args.exclude_ids ?? [];
  let apartments = sourceApartments
    .map((item) => ({
      ...item,
      apartmentId: Number(item.apartmentId ?? item.uid),
    }))
    .filter((item) => !excludeIds.some((v) => Number(v) === Number(item.apartmentId)));

  const moveIn = parseIsoDate(args.move_in_date);
  if (moveIn) {
    apartments = apartments.filter((item) => availableByMoveIn(item.availableFrom, moveIn));
  }

  const areas = (args.area ?? []).filter(Boolean);
  const types = (args.types ?? []).map((item) => typeToLabel(item)).filter(Boolean);
  const price = args.price ?? 0;
  const period = args.period;

  const downgradedType = (() => {
    const result = Math.min(...(args.types || [])) - 1;
    if (!Number.isFinite(result) || result < 0) return [];
    const label = typeToLabel(result);
    return label ? [label] : [];
  })();

  /**
   * @param {object} item
   */
  const checkArea = (item) =>
    !areas.length ||
    areas.some((v) => String(item.area || '').toLowerCase() === String(v).toLowerCase());

  /**
   * @param {object} item
   * @param {string[]} typeLabels
   */
  const checkType = (item, typeLabels) => {
    if (!typeLabels.length) return true;
    return typeLabels.some((v) => v === item.type);
  };

  /**
   * @param {object} item
   * @param {number} maxPrice
   */
  const checkPrice = (item, maxPrice) => {
    if (!maxPrice) return true;
    const priceVariants = convertPriceVariants(item);
    const priceToCheck = getPriceToCheck(priceVariants, period);
    return Boolean(priceToCheck) && priceToCheck <= maxPrice;
  };

  /** @type {object[]} */
  const result = [];

  /**
   * @param {object[]} list
   * @param {number} maxPrice
   * @param {string[]} typeLabels
   * @param {string} [comment]
   */
  const find = (list, maxPrice, typeLabels, comment) => {
    for (const item of list) {
      if (!checkArea(item)) continue;
      if (!checkType(item, typeLabels)) continue;
      if (!checkPrice(item, maxPrice)) continue;
      result.push(comment ? { ...item, comment } : item);
      apartments = apartments.filter((v) => v !== item);
      if (result.length === MAX_RESULT_SIZE) break;
    }
  };

  /**
   * @param {number} maxPrice
   * @param {string[]} typeLabels
   * @param {string} [comment]
   */
  const findPerPriceAndTypes = (maxPrice, typeLabels, comment) => {
    find(
      apartments.filter((v) => v.rentType === PREFER_RENT_TYPE && v.category === PREFER_CATEGORY),
      maxPrice,
      typeLabels,
      comment,
    );
    if (result.length < MAX_RESULT_SIZE) {
      find(
        apartments.filter((v) => v.rentType === PREFER_RENT_TYPE || v.category === PREFER_CATEGORY),
        maxPrice,
        typeLabels,
        comment,
      );
    }
    if (result.length < MAX_RESULT_SIZE) {
      find(apartments, maxPrice, typeLabels, comment);
    }
  };

  findPerPriceAndTypes(price, types);
  if (!result.length) {
    const increasedPrice = price * PRICE_FLEX;
    if (increasedPrice) {
      findPerPriceAndTypes(
        increasedPrice,
        types,
        'Цена повыше, потому что за запрашиваемую цену вариантов нет.',
      );
    }
    if (result.length < MAX_RESULT_SIZE && downgradedType.length) {
      findPerPriceAndTypes(
        price,
        downgradedType,
        'Количество комнат меньше, потому что других вариантов нет.',
      );
    }
    if (!result.length && increasedPrice && downgradedType.length) {
      findPerPriceAndTypes(
        increasedPrice,
        downgradedType,
        'Цена повыше и количество комнат меньше, потому что за запрашиваемую цену вариантов нет.',
      );
    }
  }

  return {
    matches: result,
    apartmentsPool: apartments,
    emptyCatalog: false,
    checkArea,
    checkType,
    checkPrice,
    types,
    period,
  };
}

/**
 * @param {SearchApartmentsArgs} args
 * @param {object[]} [sourceApartments]
 * @returns {{ apartments?: object[], error?: string, agentInstruction?: string }}
 */
export function searchApartments(args, sourceApartments = loadApartments()) {
  const found = findMatchingApartments(args, sourceApartments);
  if (found.emptyCatalog) {
    return {
      error: 'not_found',
      agentInstruction: 'каталог пустой, переведи на оператора',
    };
  }

  if (!found.matches.length) {
    const pricesToCheck = found.apartmentsPool
      .filter(
        (item) =>
          found.checkArea(item) &&
          found.checkType(item, found.types) &&
          found.checkPrice(item, 0),
      )
      .map((item) => getPriceToCheck(convertPriceVariants(item), found.period))
      .filter((v) => Number.isFinite(v));
    const minPrice = pricesToCheck.length ? Math.min(...pricesToCheck) : null;
    if (!minPrice) {
      return {
        error: 'not_found',
        agentInstruction: 'Вариантов нет никаких, переведи на оператора',
      };
    }
    return {
      error: 'not_found',
      agentInstruction: `Варианты есть только дороже, начиная с ${minPrice}, отработай возражение по цене и после ищи этот вариант`,
    };
  }

  return {
    apartments: found.matches.map((item) => {
      const variants = convertPriceVariants(item);
      const priceForPeriod = getPriceToCheck(variants, found.period);
      const apartment = {
        apartmentId: item.apartmentId,
        link: item.link,
        area: item.area,
        type: item.type,
        title: item.title,
        price: item.price,
        priceForPeriod: priceForPeriod ?? item.price,
        period: found.period ?? null,
        priceUnit: item.priceUnit,
        priceVariants: item.priceVariants,
        availableFrom: item.availableFrom || '',
        text: item.text,
      };
      if (item.comment) apartment.comment = item.comment;
      return { apartment };
    }),
  };
}

/**
 * Early budget classification for target|supertarget|out_of_budget.
 * Call only when days_to_checkin <= 60 and budget was stated, before showing options.
 *
 * @param {SearchApartmentsArgs & { price: number }} args
 * @param {object[]} [sourceApartments]
 * @returns {{ category?: string, hasMatch?: boolean, agentInstruction: string, error?: string }}
 */
export function classifyBudgetTarget(args, sourceApartments = loadApartments()) {
  const price = args.price ?? 0;
  const days = args.days_to_checkin;

  // Gate: only with stated budget and move-in within CLASSIFY_MAX_DAYS.
  if (!(price > 0) || days == null || days > CLASSIFY_MAX_DAYS) {
    return {
      agentInstruction: 'Продолжай сценарий в соответствии с инструкциями',
    };
  }

  if (!sourceApartments.length) {
    return {
      error: 'not_found',
      agentInstruction: 'каталог пустой, переведи на оператора',
    };
  }

  const found = findMatchingApartments(args, sourceApartments);
  if (!found.matches.length) {
    return {
      category: 'out_of_budget',
      hasMatch: false,
      agentInstruction:
        '*ВЫЗОВИ* qualify_lead с категорией out_of_budget. Дальше по сценарию: гибкие даты/районы/типы и поиск; шеринг предлагай без цен на комнаты; не уходи молча.',
    };
  }

  const category = days <= SUPER_TARGET_DAYS ? 'supertarget' : 'target';
  return {
    category,
    hasMatch: true,
    days_to_checkin: days,
    agentInstruction:
      '*ВЫЗОВИ* qualify_lead с категорией target|supertarget в зависимости от срока заезда и затем продолжай сценарий в соответствии с инструкциями.',
  };
}

