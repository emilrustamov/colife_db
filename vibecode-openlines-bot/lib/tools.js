import { loadApartments } from './catalog.js';
import { canReplaceCategory } from './categories.js';
import {
  classifyBudgetTarget,
  searchApartments,
} from './searchApartment.js';
import { bucketEscalateReason } from './analytics.js';
import { activateScenario, scenarioForCategory } from './scenarios.js';

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
    pool = apartments.filter((item) => {
      const t = String(item.type || '').toLowerCase();
      const n = t.includes('studio') ? 0 : t.includes('2') ? 2 : t.includes('1') ? 1 : t.includes('3') ? 3 : -1;
      return wanted.has(n);
    });
  }
  const prices = pool.map((i) => Number(i.price)).filter((n) => Number.isFinite(n) && n > 0);
  if (!prices.length) return { error: 'not_found' };
  return { min_price: Math.min(...prices), count: pool.length };
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
    return getMinPrice(a.types);
  }

  if (name === 'get_apartment_by_unit_number') {
    const unit = String(a.unitNumber || '').trim();
    state.unitNumber = unit || state.unitNumber;
    const found = findByUnitNumber(unit);
    if (!found) {
      state.unitLookupStatus = 'not_found';
      return { error: 'not_found' };
    }
    state.unitLookupStatus = 'found';
    if (found.link && !state.shownLinks.includes(found.link)) state.shownLinks.push(found.link);
    return {
      apartment: {
        apartmentId: Number(found.uid) || found.uid,
        title: found.title,
        link: found.link,
        area: found.area,
        type: found.type,
        price: found.price,
        availableFrom: found.availableFrom,
        unitNumber: found.unitNumber || unit,
      },
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
      if (mapped && !state.activeScenario) {
        activateScenario(state, mapped);
      }
    }
    if (a.move_in_date) state.routingMoveInDate = String(a.move_in_date);
    if (a.budget != null) state.budget = Number(a.budget);
    if (a.period != null) state.period = Number(a.period);
    if (Array.isArray(a.types)) state.types = a.types.map(Number);
    if (Array.isArray(a.area)) state.area = a.area.map(String);
    state.extras.lastQualifyReason = a.reason || '';
    if (a.dealId) state.dealId = String(a.dealId);
    if (a.contactId) state.contactId = String(a.contactId);
    return {
      ok: true,
      category: state.category,
      stub: true,
      reason: a.reason || '',
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
    return { ok: true, status, stub: true, reason: a.reason || '' };
  }

  if (name === 'set_move_in_date') {
    if (!a.contactId && !state.contactId) {
      return { ok: false, skipped: true, reason: 'contactId required' };
    }
    state.contactId = String(a.contactId || state.contactId);
    state.savedMoveInDate = String(a.move_in_date || '');
    return { ok: true, stub: true, move_in_date: state.savedMoveInDate };
  }

  if (name === 'leave_chat') {
    if (a.budget != null) state.budget = Number(a.budget);
    if (a.residents) state.residents = String(a.residents);
    if (a.stay_duration) state.stayDuration = String(a.stay_duration);
    if (a.apartment_type) state.apartmentType = String(a.apartment_type);
    if (a.planning_to_move) state.planningToMove = String(a.planning_to_move);
    if (a.dealId) state.dealId = String(a.dealId);
    if (a.contactId) state.contactId = String(a.contactId);
    if (a.planning_to_move) state.routingMoveInDate = state.routingMoveInDate || String(a.planning_to_move);
    state.extras.leaveSnapshot = {
      budget: state.budget,
      residents: state.residents,
      stay_duration: state.stayDuration,
      apartment_type: state.apartmentType,
      planning_to_move: state.planningToMove,
      category: state.category,
    };
    runtime.pendingHandoff = {
      mode: runtime.pendingHandoff?.mode || 'A',
      reason: a.reason || 'leave_chat',
      bucket: bucketEscalateReason(a.reason || 'leave_chat'),
    };
    if (runtime.pendingHandoff.mode === 'A') {
      state.terminalAPendingLeave = true;
    } else {
      state.handedOff = true;
    }
    return { ok: true, stub: true, left: true };
  }

  if (name === 'make_answer') {
    const text = String(a.text || a.message || a.answer || '').trim();
    if (text) runtime.pendingAnswers.push(text);
    return { ok: true };
  }

  if (name === 'redirect_to_operator') {
    runtime.pendingHandoff = {
      mode: 'B',
      reason: a.reason || 'redirect_to_operator',
      bucket: bucketEscalateReason(a.reason || 'redirect_to_operator'),
    };
    state.handedOff = true;
    return { ok: true };
  }

  return { error: `unknown_tool:${name}` };
}
