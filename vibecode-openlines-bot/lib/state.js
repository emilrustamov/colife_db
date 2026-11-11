/**
 * @typedef {object} DialogState
 * @property {boolean} introduced
 * @property {boolean} statusConnected
 * @property {boolean} handedOff
 * @property {boolean} operatorJoined
 * @property {string|null} category
 * @property {string|null} activeScenario
 * @property {string|null} dealId
 * @property {string|null} contactId
 * @property {string|null} routingMoveInDate
 * @property {string|null} savedMoveInDate
 * @property {number|null} budget
 * @property {number|null} period
 * @property {number[]} types
 * @property {string[]} area
 * @property {string|null} apartmentType
 * @property {string|null} residents
 * @property {string|null} stayDuration
 * @property {string|null} planningToMove
 * @property {string|null} unitNumber
 * @property {string|null} unitLookupStatus
 * @property {number[]} excludeIds
 * @property {string[]} shownLinks
 * @property {string[]} transcript
 * @property {'en'|'ru'|null} language
 * @property {boolean} terminalAPendingLeave
 * @property {number} attachmentAskCount
 * @property {object} extras
 */

/**
 * @returns {DialogState}
 */
export function createDialogState() {
  return {
    introduced: false,
    statusConnected: false,
    handedOff: false,
    operatorJoined: false,
    category: null,
    activeScenario: null,
    dealId: null,
    contactId: null,
    routingMoveInDate: null,
    savedMoveInDate: null,
    budget: null,
    period: null,
    types: [],
    area: [],
    apartmentType: null,
    residents: null,
    stayDuration: null,
    planningToMove: null,
    unitNumber: null,
    unitLookupStatus: null,
    excludeIds: [],
    shownLinks: [],
    transcript: [],
    language: null,
    terminalAPendingLeave: false,
    attachmentAskCount: 0,
    extras: {},
  };
}

/**
 * In-memory dialog store.
 */
export class DialogStore {
  constructor() {
    /** @type {Map<string, DialogState>} */
    this.map = new Map();
  }

  /**
   * @param {string} dialogId
   * @returns {DialogState}
   */
  get(dialogId) {
    const key = String(dialogId);
    if (!this.map.has(key)) this.map.set(key, createDialogState());
    return this.map.get(key);
  }

  /**
   * @param {string} dialogId
   */
  clear(dialogId) {
    this.map.delete(String(dialogId));
  }
}
