import fs from 'node:fs';
import path from 'node:path';

const DEFAULT_SOURCES = [
  {
    name: 'catalog',
    storepartuid: '159370973241',
    recid: '605208928',
    rentType: 'R2R',
    category: 'A',
    priceUnit: 'month',
  },
  {
    name: 'featured',
    storepartuid: '569020387341',
    recid: '838849572',
    rentType: 'R2R',
    category: 'A',
    priceUnit: 'month',
  },
  {
    name: 'longterm',
    storepartuid: '102753062141',
    recid: '724758034',
    rentType: 'EJARI',
    category: 'L',
    priceUnit: 'year',
  },
];

/**
 * @param {string} html
 * @returns {string}
 */
function stripHtml(html) {
  return String(html || '')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/p>/gi, '\n')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/\s+/g, ' ')
    .trim();
}

/**
 * @param {Array} characteristics
 * @returns {Record<string, string>}
 */
function charsToMap(characteristics) {
  const map = {};
  for (const row of characteristics || []) {
    if (row?.title && row?.value != null) {
      map[String(row.title)] = String(row.value);
    }
  }
  return map;
}

/**
 * @param {object} product
 * @param {object} source
 * @returns {object}
 */
function normalizeProduct(product, source) {
  const chars = charsToMap(product.characteristics);
  const price = Number.parseFloat(String(product.price || '').replace(/\s+/g, ''));
  const priceVariants = Number.isFinite(price)
    ? [
        {
          period: source.priceUnit === 'year' ? '12 months' : '1 month',
          price: String(Math.round(price)),
        },
      ]
    : [];

  return {
    uid: String(product.uid),
    title: product.title || '',
    sku: product.sku || '',
    link: product.url || '',
    area: chars.Area || '',
    type: chars.Type || '',
    capacity: chars.Capacity || '',
    availableFrom: chars['Available from'] || chars['Moving date'] || '',
    rentType: source.rentType,
    category: source.category,
    source: source.name,
    priceUnit: source.priceUnit,
    price: Number.isFinite(price) ? price : null,
    priceVariants,
    text: stripHtml(product.text || product.descr || '').slice(0, 2500),
  };
}

/**
 * @param {object} source
 * @returns {Promise<object[]>}
 */
async function fetchStorepart(source) {
  const items = [];
  for (let slice = 1; slice <= 30; slice += 1) {
    const url =
      `https://store.tildaapi.com/api/getproductslist/` +
      `?storepartuid=${source.storepartuid}&recid=${source.recid}` +
      `&slice=${slice}&getparts=true&getoptions=true`;
    const res = await fetch(url, {
      headers: {
        'User-Agent': 'Mozilla/5.0 ColifeAIBot/1.0',
        Referer: 'https://colife.ae/catalog',
      },
    });
    if (!res.ok) {
      throw new Error(`Catalog HTTP ${res.status} for ${source.name} slice ${slice}`);
    }
    const data = await res.json();
    const products = data.products || [];
    if (!products.length) break;
    for (const product of products) {
      items.push(normalizeProduct(product, source));
    }
    if (items.length >= Number(data.total || 0)) break;
  }
  return items;
}

/**
 * @returns {string}
 */
export function catalogJsonPath() {
  return process.env.CATALOG_JSON_PATH || '/opt/data/state/catalog.json';
}

/**
 * @returns {string}
 */
export function catalogTextPath() {
  return process.env.CATALOG_PATH || '/opt/data/state/catalog.txt';
}

/**
 * @returns {Promise<{ ok: boolean, count: number, path: string, updatedAt: string, error?: string, filters?: object }>}
 */
export async function refreshCatalogFromApi() {
  const updatedAt = new Date().toISOString();
  const jsonPath = catalogJsonPath();
  const textPath = catalogTextPath();
  try {
    const sources = DEFAULT_SOURCES;
    const all = [];
    const seen = new Set();
    let filters = null;
    for (const source of sources) {
      const items = await fetchStorepart(source);
      if (source.name === 'catalog' && items.length) {
        // keep filter labels from first page via lightweight re-fetch
      }
      for (const item of items) {
        const key = `${item.source}:${item.uid}`;
        if (seen.has(key)) continue;
        seen.add(key);
        all.push(item);
      }
    }

    // fetch filters metadata from main catalog
    const filterRes = await fetch(
      `https://store.tildaapi.com/api/getproductslist/?storepartuid=159370973241&recid=605208928&slice=1&getparts=true&getoptions=true`,
      {
        headers: {
          'User-Agent': 'Mozilla/5.0 ColifeAIBot/1.0',
          Referer: 'https://colife.ae/catalog',
        },
      },
    );
    if (filterRes.ok) {
      const filterData = await filterRes.json();
      filters = filterData.filters || null;
    }

    const payload = {
      updatedAt,
      count: all.length,
      filters,
      apartments: all,
    };
    fs.mkdirSync(path.dirname(jsonPath), { recursive: true });
    fs.writeFileSync(jsonPath, JSON.stringify(payload), 'utf8');

    const lines = [
      `# Catalog API snapshot ${updatedAt}`,
      `Objects: ${all.length}`,
      'Use search_apartment filters: area, type (Studio/1br/2br), max price, period months.',
      '',
    ];
    for (const item of all.slice(0, 40)) {
      lines.push(
        [
          `### ${item.title}`,
          `link: ${item.link}`,
          `area: ${item.area}; type: ${item.type}; price: ${item.price} AED/${item.priceUnit}`,
          `available: ${item.availableFrom}; rentType: ${item.rentType}`,
          item.text.slice(0, 400),
        ].join('\n'),
      );
    }
    fs.writeFileSync(textPath, `${lines.join('\n\n')}\n`, 'utf8');

    return {
      ok: true,
      count: all.length,
      path: jsonPath,
      updatedAt,
      filters: summarizeFilters(filters),
    };
  } catch (err) {
    return {
      ok: false,
      count: 0,
      path: jsonPath,
      updatedAt,
      error: err.message,
    };
  }
}

/**
 * @param {object|null} filters
 * @returns {object|null}
 */
function summarizeFilters(filters) {
  if (!filters?.filters) return null;
  const out = {};
  for (const f of filters.filters) {
    out[f.label] = (f.values || []).map((v) => v.value);
  }
  return out;
}

/**
 * @returns {object[]}
 */
export function loadApartments() {
  try {
    const raw = fs.readFileSync(catalogJsonPath(), 'utf8');
    const data = JSON.parse(raw);
    return Array.isArray(data.apartments) ? data.apartments : [];
  } catch {
    return [];
  }
}
