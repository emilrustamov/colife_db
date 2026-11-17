/**
 * Product / routing knobs (not secrets — secrets stay in .env).
 */
export default {
  postponedFromDate: '2026-12-10',
  search: {
    maxResults: 3,
    priceFlex: 1.3,
    preferRentType: 'R2R',
    preferCategory: 'A',
  },
  classify: {
    superTargetDays: 30,
    maxDays: 60,
    unlimitedBudget: 99999,
  },
};
