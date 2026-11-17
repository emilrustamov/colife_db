import { loadApartments } from './catalog.js';
import { canReplaceCategory } from './categories.js';
import {
  classifyBudgetTarget,
  searchApartments,
  unitFitsMoveIn,
} from './searchApartment.js';
import { bucketEscalateReason } from './analytics.js';
import {
  activateScenario,
  blockApartmentCatalogTools,
  resolveApartmentType,
  roomsFromCatalogType,
  scenarioForCategory,
} from './scenarios.js';
import {
  crmEnabled,
  writeCategory,
  writeChatStatus,
  writeLeaveSnapshot,
  writeMoveInDate,
} from './crm.js';

/**
 * @param {string} unitNumber
 * @param {object[]} apartments
 * @returns {object|null}
 */
export function findByUnitNumber(unitNumber, apartments = loadApartments()) {
  const needle = String(unitNumber || '').trim().toLowerCase();
  if (!needle) return null;
  for (const item of apartments) {
    const hay = [item.unitNumber, item.sku, item.title, item.uid, item.text]
      .filter(Boolean)
      .join(' ')
      .toLowerCase();
    if (hay.includes(needle)) return item;
    const m = String(item.title || '').match(/\b(\d+(?:[./-]\d+[a-zA-Z]?)*)\b/);
    if (m && m[1].toLowerCase() === needle) return item;
  }
  return null;
}

/**
 * @param {number[]|undefined} types
 * @returns {{ min_price?: number, error?: string }}
 */
export function getMinPrice(types) {
  const apartments = loadApartments();
  let pool = apartments;
  if (Array.isArray(types) && types.length) {
    const wanted = new Set(types.map(Number));
    pool = apartments.filter((item) => wanted.has(roomsFromCatalogType(item.type)));
  }
  const prices = pool.map((i) => Number(i.price)).filter((n) => Number.isFinite(n) && n > 0);
  if (!prices.length) return { error: 'not_found' };
  return { min_price: Math.min(...prices), count: pool.length };
}

/**
 * Apply a catalog unit hit onto dialog state (robot path + get_apartment tool).
 *
 * @param {import('./state.js').DialogState} state
 * @param {object|null} found
 * @returns {object|null}
 */
export function applyCatalogUnitToState(state, found) {
  if (!found) {
    state.unitLookupStatus = 'not_found';
    return null;
  }
  state.unitLookupStatus = 'found';
  if (found.link && !state.shownLinks.includes(found.link)) {
    state.shownLinks.push(found.link);
  }
  const rooms = roomsFromCatalogType(found.type);
  if (rooms != null && !state.types.length) state.types = [rooms];
  if (found.area && !state.area.length) state.area = [String(found.area)];
  return found;
}

/**
 * @param {import('./state.js').DialogState} state
 * @param {string} name
 * @param {object} args
 * @param {{ pendingAnswers?: string[], pendingHandoff?: object|null }} runtime
 * @returns {Promise<object>}
 */
export async function executeTool(state, name, args, runtime) {
  const a = args && typeof args === 'object' ? args : {};

  if (name === 'search_apartments') {
    const blocked = blockApartmentCatalogTools(state);
    if (blocked) return { ...blocked };
    if (Array.isArray(a.types) && a.types.length) state.types = a.types.map(Number);
    if (a.period != null && Number(a.period) > 0) state.period = Number(a.period);
    if (a.price != null && Number(a.price) > 0) state.budget = Number(a.price);
    if (a.move_in_date) state.routingMoveInDate = String(a.move_in_date);
    if (Array.isArray(a.area) && a.area.length) state.area = a.area.map(String);
    const exclude = Array.isArray(a.exclude_ids) ? a.exclude_ids.map(Number) : state.excludeIds.slice();
    const result = searchApartments({
      area: a.area || state.area,
      price: a.price ?? state.budget,
      types: a.types || state.types,
      period: a.period ?? state.period,
      exclude_ids: exclude,
      move_in_date: a.move_in_date || state.routingMoveInDate,
    });
    if (result.apartments) {
      for (const row of result.apartments) {
        const id = Number(row.apartment?.apartmentId);
        if (Number.isFinite(id) && !state.excludeIds.includes(id)) state.excludeIds.push(id);
        if (row.apartment?.link && !state.shownLinks.includes(row.apartment.link)) {
          state.shownLinks.push(row.apartment.link);
        }
      }
    }
    return result;
  }

  if (name === 'get_min_price') {
    const blocked = blockApartmentCatalogTools(state);
    if (blocked) return { ...blocked };
    return getMinPrice(a.types);
  }

  if (name === 'get_apartment_by_unit_number') {
    const unit = String(a.unitNumber || '').trim();
    state.unitNumber = unit || state.unitNumber;
    const found = applyCatalogUnitToState(state, findByUnitNumber(unit));
    if (!found) return { error: 'not_found' };
    const fit = unitFitsMoveIn(
      found.availableFrom,
      state.routingMoveInDate || state.savedMoveInDate,
    );
    const agentInstruction = !fit.known
      ? 'availableFrom unknown — confirm availability; do not claim the unit is free now. Use priceVariants for period.'
      : fit.fits === false
        ? `availableFrom ${fit.availableFrom} is AFTER move-in ${fit.moveIn}. Tell that date; offer wait or alternatives — not free on move-in.`
        : `availableFrom ${fit.availableFrom || 'n/a'}; use priceVariants for period. State availableFrom when quoting.`;
    return {
      apartment: {
        apartmentId: Number(found.uid) || found.uid,
        title: found.title,
        link: found.link,
        area: found.area,
        type: found.type,
        price: found.price,
        priceUnit: found.priceUnit,
        priceVariants: found.priceVariants || [],
        availableFrom: found.availableFrom || '',
        unitNumber: found.unitNumber || unit,
        fitsMoveIn: fit.fits,
        availabilityKnown: fit.known,
      },
      agentInstruction,
    };
  }

  if (name === 'classify_budget_target') {
    if (state.category) {
      return {
        skipped: true,
        category: state.category,
        agentInstruction: 'category already set; do not re-qualify this turn',
      };
    }
    const price = Number(a.price);
    if (a.move_in_date) state.routingMoveInDate = String(a.move_in_date);
    if (Number.isFinite(price) && price > 0) state.budget = price;
    const result = classifyBudgetTarget({
      price,
      types: a.types || state.types,
      area: a.area || state.area,
      period: a.period ?? state.period,
      days_to_checkin: a.days_to_checkin ?? state.extras.days_to_checkin,
      exclude_ids: state.excludeIds,
      move_in_date: a.move_in_date || state.routingMoveInDate,
    });
    if (result.category && canReplaceCategory(state.category, result.category)) {
      state.category = result.category;
    }
    return result;
  }

  if (name === 'qualify_lead') {
    const category = String(a.category || '');
    if (category && canReplaceCategory(state.category, category)) {
      state.category = category;
      const mapped = scenarioForCategory(category);
      if (mapped) {
        activateScenario(state, mapped);
      }
    }
    if (category === 'sharing' && !state.apartmentType) state.apartmentType = 'room';
    if (a.move_in_date) state.routingMoveInDate = String(a.move_in_date);
    if (a.budget != null) state.budget = Number(a.budget);
    if (a.period != null) state.period = Number(a.period);
    if (Array.isArray(a.types)) state.types = a.types.map(Number);
    if (Array.isArray(a.area)) state.area = a.area.map(String);
    if (!state.apartmentType) state.apartmentType = resolveApartmentType(state);
    state.extras.lastQualifyReason = a.reason || '';
    if (a.dealId) state.dealId = String(a.dealId);
    if (a.contactId) state.contactId = String(a.contactId);
    let crm = { ok: true, stub: !crmEnabled() };
    try {
      if (state.category) crm = await writeCategory(state, state.category);
    } catch (err) {
      crm = { ok: false, stub: false, error: err.message };
    }
    state.extras.crmWrites = [...(state.extras.crmWrites || []), { tool: 'qualify_lead', ...crm }];
    return {
      ok: Boolean(crm.ok),
      category: state.category,
      stub: Boolean(crm.stub),
      dealId: state.dealId || '',
      reason: a.reason || '',
      crm,
    };
  }

  if (name === 'activate_scenario') {
    return activateScenario(state, a.scenario || a.name || null);
  }

  if (name === 'set_wikibot_in_chat_status') {
    const status = String(a.status || '');
    state.extras.botChatStatus = status;
    if (status === 'connected') state.statusConnected = true;
    if (status === 'disconnected') state.statusConnected = false;
    if (a.dealId) state.dealId = String(a.dealId);
    if (a.contactId) state.contactId = String(a.contactId);
    let crm = { ok: true, stub: !crmEnabled() };
    try {
      if (status) crm = await writeChatStatus(state, status);
    } catch (err) {
      crm = { ok: false, stub: false, error: err.message };
    }
    state.extras.crmWrites = [...(state.extras.crmWrites || []), { tool: 'set_wikibot_in_chat_status', ...crm }];
    return {
      ok: Boolean(crm.ok),
      status,
      stub: Boolean(crm.stub),
      dealId: state.dealId || '',
      reason: a.reason || '',
      crm,
    };
  }

  if (name === 'set_move_in_date') {
    if (a.dealId) state.dealId = String(a.dealId);
    if (a.contactId) state.contactId = String(a.contactId);
    if (!state.dealId && !crmEnabled() && !a.contactId && !state.contactId) {
      return { ok: false, skipped: true, reason: 'dealId required' };
    }
    state.savedMoveInDate = String(a.move_in_date || '');
    let crm = { ok: true, stub: !crmEnabled() };
    try {
      if (state.savedMoveInDate) crm = await writeMoveInDate(state, state.savedMoveInDate);
    } catch (err) {
      crm = { ok: false, stub: false, error: err.message };
    }
    state.extras.crmWrites = [...(state.extras.crmWrites || []), { tool: 'set_move_in_date', ...crm }];
    return {
      ok: Boolean(crm.ok),
      stub: Boolean(crm.stub),
      dealId: state.dealId || '',
      move_in_date: state.savedMoveInDate,
      crm,
    };
  }

  if (name === 'leave_chat') {
    // CRM enums come from enrichStateForLeave — do not take stay/type/planning from model args.
    if (a.budget != null) state.budget = Number(a.budget);
    if (a.residents && !state.residents) state.residents = String(a.residents);
    if (a.dealId) state.dealId = String(a.dealId);
    if (a.contactId) state.contactId = String(a.contactId);
    let crm = { ok: true, stub: !crmEnabled() };
    try {
      crm = await writeLeaveSnapshot(state);
    } catch (err) {
      crm = { ok: false, stub: false, error: err.message };
    }
    state.extras.crmWrites = [...(state.extras.crmWrites || []), { tool: 'leave_chat', ...crm }];
    runtime.pendingHandoff = {
      mode: runtime.pendingHandoff?.mode || 'A',
      reason: a.reason || 'leave_chat',
      bucket: bucketEscalateReason(a.reason || 'leave_chat'),
    };
    if (runtime.pendingHandoff.mode === 'A') {
      state.terminalAPendingLeave = true;
    }
    return {
      ok: Boolean(crm.ok),
      stub: Boolean(crm.stub),
      left: true,
      dealId: state.dealId || '',
      crm,
    };
  }

  if (name === 'make_answer') {
    const text = String(a.text || '').trim();
    // One client bubble per turn — last make_answer wins (stops duplicate spam).
    if (text) runtime.pendingAnswers = [text];
    return { ok: true };
  }

  if (name === 'redirect_to_operator') {
    runtime.pendingHandoff = {
      mode: 'B',
      reason: a.reason || 'redirect_to_operator',
      bucket: bucketEscalateReason(a.reason || 'redirect_to_operator'),
    };
    return { ok: true };
  }

  return { error: `unknown_tool:${name}` };
}
