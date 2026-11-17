/**
 * Single apartment-type vocabulary: search rooms int ↔ catalog label ↔ CRM key.
 */

/** @type {Array<{ rooms: number, catalog: string, crm: string }>} */
export const ROOM_TYPES = [
  { rooms: 0, catalog: 'Studio', crm: 'studio' },
  { rooms: 1, catalog: '1br Apartment', crm: '1_bedroom' },
  { rooms: 2, catalog: '2br Apartment', crm: '2_bedroom' },
  { rooms: 3, catalog: '3br Apartment', crm: '3_bedroom' },
];

/**
 * @param {number|null|undefined} rooms
 * @returns {string}
 */
export function typeToLabel(rooms) {
  const row = ROOM_TYPES.find((r) => r.rooms === Number(rooms));
  return row ? row.catalog : '';
}

/**
 * @param {number|null|undefined} rooms
 * @returns {string|null}
 */
export function apartmentTypeFromRooms(rooms) {
  const row = ROOM_TYPES.find((r) => r.rooms === Number(rooms));
  return row ? row.crm : null;
}

/**
 * Catalog / tool type label → rooms filter code.
 *
 * @param {string|null|undefined} type
 * @returns {number|null}
 */
export function roomsFromCatalogType(type) {
  const t = String(type || '').toLowerCase();
  if (/\bstudio\b|студи/.test(t)) return 0;
  if (/\b3\s*br\b|\b3\b.*bed|\b3\s*bedroom/.test(t)) return 3;
  if (/\b2\s*br\b|\b2\b.*bed|\b2\s*bedroom/.test(t)) return 2;
  if (/\b1\s*br\b|\b1\b.*bed|\b1\s*bedroom/.test(t)) return 1;
  return null;
}
