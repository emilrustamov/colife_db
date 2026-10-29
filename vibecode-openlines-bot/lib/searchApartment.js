import { loadApartments } from './catalog.js';

const MAX_RESULT_SIZE = 3;
const SUPER_TARGET_DAYS = 30;
const CLASSIFY_MAX_DAYS = 60;
const UNLIMITED_BUDGET = 99999;

/**
 * @typedef {object} SearchApartmentsArgs
 * @property {string[]} [area]
 * @property {number} [price]
 * @property {number[]} [types]
 * @property {number} [period]
 * @property {number[]} [exclude_ids]
 * @property {number} [days_to_checkin]
 * @property {boolean} [areaFlexible]
 * @property {number} [guests]
 */

/**
 * @param {number} type
 * @returns {string}
 */
function typeToLabel(type) {
  switch (type) {
    case 0:
      return 'Studio';
    case 1:
      return '1br Apartment';
    case 2:
      return '2br Apartment';
    default:
      return '';
  }
}

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
 * @param {string} text
 * @returns {number|undefined}
 */
export function extractDaysToCheckin(text) {
  const t = String(text || '');
  if (/\b(asap|urgent|сейчас|сегодня|завтра|as soon as)\b/i.test(t)) return 1;

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

  const rangeRu = t.match(
    /(?:с|from)?\s*(\d{1,2})\s*[-–—]\s*(\d{1,2})\s*(январ|феврал|март|апрел|ма[йя]|июн|июл|август|сентябр|октябр|ноябр|декабр|jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i,
  );
  if (rangeRu) {
    return daysUntilDate(
      Number.parseInt(rangeRu[1], 10),
      monthIndexFromName(rangeRu[3]),
    );
  }

  const fromDayMonth = t.match(
    /(?:с|from|заезд|check[\s-]?in|переезд)\s*(?:с|from)?\s*(\d{1,2})\s*(январ|феврал|март|апрел|ма[йя]|июн|июл|август|сентябр|октябр|ноябр|декабр|jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i,
  );
  if (fromDayMonth) {
    return daysUntilDate(
      Number.parseInt(fromDayMonth[1], 10),
      monthIndexFromName(fromDayMonth[2]),
    );
  }

  const dateMatch = t.match(/(\d{1,2})[./-](\d{1,2})(?:[./-](\d{2,4}))?/);
  if (dateMatch) {
    let year;
    if (dateMatch[3]) {
      year = Number.parseInt(dateMatch[3], 10);
      if (year < 100) year += 2000;
    }
    return daysUntilDate(
      Number.parseInt(dateMatch[1], 10),
      Number.parseInt(dateMatch[2], 10) - 1,
      year,
    );
  }

  return undefined;
}

/**
 * @param {string} text
 * @returns {SearchApartmentsArgs}
 */
export function extractSearchArgs(text) {
  const t = String(text || '');
  const lower = t.toLowerCase();

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
    if (price == null) {
      const priceMatch = t.match(
        /(\d{1,3}(?:[\s,]?\d{3})+|\d+)\s*(?:aed|дирхам|дерхам|дирхем|бюджет|до|max|under)?/i,
      );
      if (priceMatch) {
        const n = Number.parseInt(priceMatch[1].replace(/[\s,]/g, ''), 10);
        if (Number.isFinite(n) && n >= 1000) price = n;
      }
    }
  }

  let period;
  const periodMatch = t.match(/(\d+)\s*(?:month|months|мес|месяц)/i);
  if (periodMatch) period = Number.parseInt(periodMatch[1], 10);
  else if (/на\s*месяц|for\s*a?\s*month|1\s*month/i.test(t)) period = 1;
  else if (/год|year|ejari/i.test(t)) period = 12;

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

  const knownAreas = [
    'Business Bay',
    'Downtown',
    'Dubai Marina',
    'Marina',
    'JLT',
    'JVC',
    'JBR',
    'Sobha Hartland',
    'Al Jadaf',
    'Al Jaddaf',
    'Damac Hills',
    'Palm Deira',
    'Dubai Creek Harbour',
    'Creek Harbour',
    'Jumeirah 1',
    'DIFC',
    'Discovery Gardens',
    'Dubai Hills',
    'The Greens',
    'Palm Jumeirah',
    'Dubai South',
    'Dubai Production City',
    'Madinat Jumeirah Living',
    'Meydan',
    'Al Furjan',
  ];
  /** @type {string[]} */
  const area = [];
  for (const name of knownAreas) {
    if (!lower.includes(name.toLowerCase())) continue;
    const normalized =
      name === 'Marina'
        ? 'Dubai Marina'
        : name === 'Al Jaddaf'
          ? 'Al Jadaf'
          : name === 'Creek Harbour'
            ? 'Dubai Creek Harbour'
            : name;
    if (!area.some((a) => a.toLowerCase() === normalized.toLowerCase())) {
      area.push(normalized);
    }
  }

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
 * @param {SearchApartmentsArgs} args
 * @returns {string}
 */
export function formatKnownFiltersForPrompt(args) {
  const lines = ['### Known lead filters (already collected — DO NOT ask again)'];
  if (args.price != null) {
    lines.push(
      args.price >= 99999
        ? '- budget: unlimited'
        : `- budget max: ${args.price} AED/month`,
    );
  }
  if (args.types?.length) {
    const labels = args.types.map((t) =>
      t === 0 ? 'Studio' : t === 1 ? '1br' : t === 2 ? '2br' : String(t),
    );
    lines.push(`- types: ${labels.join(', ')}`);
  }
  if (args.areaFlexible) lines.push('- area: any / no preference');
  else if (args.area?.length) lines.push(`- areas: ${args.area.join(', ')}`);
  if (args.period != null) lines.push(`- rental period: ~${args.period} month(s)`);
  if (args.days_to_checkin != null) {
    lines.push(`- days_to_checkin: ${args.days_to_checkin}`);
  }
  if (args.guests != null) lines.push(`- guests: ${args.guests}`);
  if (lines.length === 1) lines.push('- (none yet)');
  lines.push(
    'Ask ONLY missing fields. If type + budget are known and area is any/known, show options or say you will pick from catalog.',
  );
  return lines.join('\n');
}

/**
 * @param {string} text
 * @returns {boolean}
 */
export function looksLikeApartmentSearch(text) {
  return /(apart|студи|комнат|каталог|снять|сниму|ищу|иду\s*квартир|аренд|жиль|budget|бюджет|дирхам|дерхам|1br|2br|studio|район|marina|jvc|jlt|downtown|business bay|квартир|ещё|еще вариант|other option|show more|покажи|вариант|на\s*двоих)/i.test(
    String(text || ''),
  );
}

/**
 * @param {string} text
 * @returns {boolean}
 */
export function looksLikeShowOptions(text) {
  return /(покажи|вариант|options?|show\s*(me)?|подбер|найди|search|ещё|еще\s*вариант|other\s*option|what\s*do\s*you\s*have|что\s*есть)/i.test(
    String(text || ''),
  );
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
      apartments.filter((v) => v.rentType === 'R2R' && v.category === 'A'),
      maxPrice,
      typeLabels,
      comment,
    );
    if (result.length < MAX_RESULT_SIZE) {
      find(
        apartments.filter((v) => v.rentType === 'R2R' || v.category === 'A'),
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
    const increasedPrice = price * 1.3;
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
      const apartment = {
        apartmentId: item.apartmentId,
        link: item.link,
        area: item.area,
        type: item.type,
        title: item.title,
        price: item.price,
        priceUnit: item.priceUnit,
        priceVariants: item.priceVariants,
        availableFrom: item.availableFrom,
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
  const isPriceKnown = price > 0;
  const days = args.days_to_checkin;

  if (!sourceApartments.length) {
    return {
      error: 'not_found',
      agentInstruction: 'каталог пустой, переведи на оператора',
    };
  }

  const found = findMatchingApartments(args, sourceApartments);
  const hasMatch = found.matches.length > 0;

  if (!isPriceKnown) {
    return {
      hasMatch,
      agentInstruction: 'Продолжай сценарий в соответствии с инструкциями',
    };
  }

  if (!hasMatch) {
    return {
      category: 'out_of_budget',
      hasMatch: false,
      agentInstruction:
        '*ВЫЗОВИ* qualify_lead с категорией out_of_budget и затем продолжай сценарий в соответствии с инструкциями.',
    };
  }

  const category =
    days != null && days <= SUPER_TARGET_DAYS ? 'supertarget' : 'target';

  return {
    category,
    hasMatch: true,
    days_to_checkin: days,
    agentInstruction:
      '*ВЫЗОВИ* qualify_lead с категорией target|supertarget в зависимости от срока заезда и затем продолжай сценарий в соответствии с инструкциями.',
  };
}

/**
 * @param {SearchApartmentsArgs} args
 * @param {boolean} [alreadyClassified]
 * @returns {boolean}
 */
export function shouldClassifyBudgetTarget(args, alreadyClassified = false) {
  if (alreadyClassified) return false;
  const price = args.price ?? 0;
  if (!(price > 0)) return false;
  const days = args.days_to_checkin;
  if (days == null || days > CLASSIFY_MAX_DAYS) return false;
  return true;
}

/**
 * Backward-compatible helpers used by older call sites/tests.
 * @param {object} args
 * @param {object[]} [apartments]
 */
export function searchApartment(args, apartments = loadApartments()) {
  const normalized = {
    area: args.area ? [].concat(args.area).filter(Boolean) : [],
    price: args.price != null && args.price >= 0 ? args.price : undefined,
    types: args.type != null && args.type >= 0 ? [args.type] : args.types || [],
    period: args.period != null && args.period >= 0 ? args.period : undefined,
    exclude_ids: args.exclude_ids || [],
  };
  const result = searchApartments(normalized, apartments);
  if (result.error) return { error: result.error, agentInstruction: result.agentInstruction };
  return { apartment: result.apartments?.[0]?.apartment, matches: result.apartments?.map((x) => x.apartment) };
}

/**
 * @param {object} args
 * @param {number} [limit]
 */
export function searchApartmentList(args, limit = MAX_RESULT_SIZE) {
  const normalized = {
    area: args.area ? [].concat(args.area).filter(Boolean) : [],
    price: args.price != null && args.price > 0 ? args.price : undefined,
    types:
      args.types ||
      (args.type != null && args.type >= 0 ? [args.type] : []),
    period: args.period != null && args.period > 0 ? args.period : undefined,
    exclude_ids: args.exclude_ids || [],
  };
  const result = searchApartments(normalized);
  if (result.apartments?.length) {
    return result.apartments.slice(0, limit).map((row) => row.apartment);
  }
  return [];
}

/**
 * @param {object[]} matches
 * @param {string} [agentInstruction]
 * @returns {string}
 */
export function formatMatchesForPrompt(matches, agentInstruction = '') {
  if (!matches.length) {
    return [
      'Apartment search result: not_found.',
      agentInstruction || 'Ask one clarifying question only if needed, otherwise hand off.',
    ].join('\n');
  }
  return matches
    .map((item, idx) => {
      const comment = item.comment ? `\ncomment: ${item.comment}` : '';
      return [
        `#${idx + 1}: ${item.title || `${item.type} in ${item.area}`}`,
        `apartmentId: ${item.apartmentId}`,
        `link: ${item.link}`,
        `area: ${item.area}; type: ${item.type}; price: ${item.price} AED/${item.priceUnit || 'month'}`,
        `available: ${item.availableFrom || 'n/a'}${comment}`,
        `details: ${String(item.text || '').slice(0, 700)}`,
      ].join('\n');
    })
    .join('\n\n');
}

/**
 * @param {{ category?: string, hasMatch?: boolean, agentInstruction?: string, days_to_checkin?: number }} result
 * @returns {string}
 */
export function formatClassifyForPrompt(result) {
  return [
    '### Budget classification (classify_budget_target)',
    `category: ${result.category || 'n/a'}`,
    `hasMatch: ${Boolean(result.hasMatch)}`,
    result.days_to_checkin != null ? `days_to_checkin: ${result.days_to_checkin}` : null,
    `agentInstruction: ${result.agentInstruction || ''}`,
    'Do not show apartments yet unless the customer asked for options. Follow agentInstruction for lead category.',
  ]
    .filter(Boolean)
    .join('\n');
}
