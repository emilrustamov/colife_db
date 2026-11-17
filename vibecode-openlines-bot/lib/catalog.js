import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { getPool, query } from './db.js';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');

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
 * @returns {string}
 */
function editionSku(product) {
  for (const ed of product.editions || []) {
    const sku = String(ed?.sku || '').trim();
    if (sku) return sku;
  }
  return '';
}

/**
 * @param {object} product
 * @param {object} source
 * @returns {Array<{ period: string, price: string }>}
 */
function editionPriceVariants(product, source) {
  const variants = [];
  for (const ed of product.editions || []) {
    const price = Number.parseFloat(String(ed?.price || '').replace(/\s+/g, ''));
    if (!Number.isFinite(price)) continue;
    const period = String(ed?.['Contract period'] || '').trim();
    variants.push({
      period: period || (source.priceUnit === 'year' ? '12 months' : '1 month'),
      price: String(Math.round(price)),
    });
  }
  return variants;
}

/**
 * @param {object} product
 * @param {object} source
 * @returns {object}
 */
function normalizeProduct(product, source) {
  const chars = charsToMap(product.characteristics);
  const fromEditions = editionPriceVariants(product, source);
  const basePrice = Number.parseFloat(String(product.price || '').replace(/\s+/g, ''));
  const priceVariants = fromEditions.length
    ? fromEditions
    : Number.isFinite(basePrice)
      ? [
          {
            period: source.priceUnit === 'year' ? '12 months' : '1 month',
            price: String(Math.round(basePrice)),
          },
        ]
      : [];
  const price = priceVariants.length
    ? Number.parseFloat(priceVariants[0].price)
    : Number.isFinite(basePrice)
      ? basePrice
      : null;
  const skuFromEdition = editionSku(product);
  const unitNumber =
    chars['Unit number'] ||
    chars['Unit Number'] ||
    chars.Unit ||
    chars.SKU ||
    product.sku ||
    skuFromEdition ||
    '';

  return {
    uid: String(product.uid),
    title: product.title || '',
    sku: product.sku || skuFromEdition || '',
    unitNumber: String(unitNumber || ''),
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

/** @type {object[]|null} */
let apartmentsCache = null;

/**
 * Optional JSON backup path (debug / cold fallback only).
 *
 * @returns {string}
 */
export function catalogJsonPath() {
  return process.env.CATALOG_JSON_PATH || path.join(ROOT, 'data', 'state', 'catalog.json');
}

/**
 * @param {object} row
 * @returns {object}
 */
function rowToApartment(row) {
  let priceVariants = [];
  try {
    const raw = row.price_variants_json;
    priceVariants = typeof raw === 'string' ? JSON.parse(raw || '[]') : raw || [];
  } catch {
    priceVariants = [];
  }
  if (!Array.isArray(priceVariants)) priceVariants = [];
  const price = row.price != null ? Number(row.price) : null;
  return {
    uid: String(row.uid),
    apartmentId: Number(row.uid) || row.uid,
    title: String(row.title || ''),
    sku: String(row.sku || ''),
    unitNumber: String(row.unit_number || ''),
    link: String(row.link || ''),
    area: String(row.area || ''),
    type: String(row.type || ''),
    capacity: String(row.capacity || ''),
    availableFrom: String(row.available_from || ''),
    rentType: String(row.rent_type || ''),
    category: String(row.category || ''),
    source: String(row.source || ''),
    priceUnit: String(row.price_unit || ''),
    price: Number.isFinite(price) ? price : null,
    priceVariants,
    text: String(row.text_body || ''),
  };
}

/**
 * Replace in-memory catalog cache.
 *
 * @param {object[]} apartments
 */
export function setApartmentsCache(apartments) {
  apartmentsCache = Array.isArray(apartments) ? apartments : [];
}

/**
 * Load apartments from DB into memory. Falls back to JSON backup if DB empty/unavailable.
 *
 * @returns {Promise<object[]>}
 */
export async function warmApartmentsCache() {
  try {
    if (getPool()) {
      const rows = await query(
        `SELECT uid, source, title, sku, unit_number, link, area, type, capacity, available_from,
                rent_type, category, price_unit, price, price_variants_json, text_body
         FROM ol_bot_apartments`,
      );
      if (Array.isArray(rows) && rows.length) {
        setApartmentsCache(rows.map(rowToApartment));
        return apartmentsCache;
      }
    }
  } catch {
    // fall through to JSON
  }
  try {
    const raw = fs.readFileSync(catalogJsonPath(), 'utf8');
    const data = JSON.parse(raw);
    const list = Array.isArray(data.apartments) ? data.apartments : [];
    setApartmentsCache(list);
    return apartmentsCache;
  } catch {
    setApartmentsCache([]);
    return apartmentsCache;
  }
}

/**
 * @returns {Promise<{ ok: boolean, count: number, path: string, updatedAt: string, error?: string, filters?: object }>}
 */
export async function refreshCatalogFromApi() {
  const updatedAt = new Date().toISOString();
  const jsonPath = catalogJsonPath();
  try {
    const sources = DEFAULT_SOURCES;
    const all = [];
    const seen = new Set();
    let filters = null;
    for (const source of sources) {
      const items = await fetchStorepart(source);
      for (const item of items) {
        const key = `${item.source}:${item.uid}`;
        if (seen.has(key)) continue;
        seen.add(key);
        all.push(item);
      }
    }

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

    await syncApartmentsToDb(all, updatedAt);
    setApartmentsCache(all);

    try {
      fs.mkdirSync(path.dirname(jsonPath), { recursive: true });
      fs.writeFileSync(
        jsonPath,
        JSON.stringify({ updatedAt, count: all.length, filters, apartments: all }),
        'utf8',
      );
    } catch {
      // JSON backup optional
    }

    return {
      ok: true,
      count: all.length,
      path: 'ol_bot_apartments',
      updatedAt,
      filters: summarizeFilters(filters),
    };
  } catch (err) {
    return {
      ok: false,
      count: apartmentsCache?.length || 0,
      path: 'ol_bot_apartments',
      updatedAt,
      error: err.message,
    };
  }
}

/**
 * @param {object[]} apartments
 * @param {string} updatedAt
 * @returns {Promise<void>}
 */
export async function syncApartmentsToDb(apartments, updatedAt) {
  if (!getPool()) return;
  await query('DELETE FROM ol_bot_apartments');
  for (const item of apartments) {
    await query(
      `INSERT INTO ol_bot_apartments
        (uid, source, title, sku, unit_number, link, area, type, capacity, available_from,
         rent_type, category, price_unit, price, price_variants_json, text_body, synced_at)
       VALUES
        (:uid, :source, :title, :sku, :unit_number, :link, :area, :type, :capacity, :available_from,
         :rent_type, :category, :price_unit, :price, :price_variants_json, :text_body, :synced_at)`,
      {
        uid: String(item.uid),
        source: String(item.source || ''),
        title: String(item.title || ''),
        sku: String(item.sku || ''),
        unit_number: String(item.unitNumber || ''),
        link: String(item.link || ''),
        area: String(item.area || ''),
        type: String(item.type || ''),
        capacity: String(item.capacity || ''),
        available_from: String(item.availableFrom || ''),
        rent_type: String(item.rentType || ''),
        category: String(item.category || ''),
        price_unit: String(item.priceUnit || ''),
        price: item.price != null ? Number(item.price) : null,
        price_variants_json: JSON.stringify(item.priceVariants || []),
        text_body: String(item.text || '').slice(0, 5000),
        synced_at: updatedAt.slice(0, 19).replace('T', ' '),
      },
    );
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
 * In-memory catalog (warmed from DB on boot / refresh).
 *
 * @returns {object[]}
 */
export function loadApartments() {
  return apartmentsCache || [];
}
