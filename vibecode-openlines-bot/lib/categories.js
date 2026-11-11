const RANK = {
  no_information: 10,
  student: 20,
  target: 40,
  sharing: 50,
  short_term: 55,
  ejari: 55,
  postponed: 60,
  out_of_budget: 70,
  non_target: 80,
  supertarget: 90,
};

/**
 * @param {string|null|undefined} current
 * @param {string} next
 * @returns {boolean}
 */
export function canReplaceCategory(current, next) {
  if (!next) return false;
  if (!current) return true;
  if (current === next) return false;
  if (current === 'student') {
    return ['target', 'supertarget', 'postponed', 'short_term', 'out_of_budget', 'sharing', 'ejari', 'non_target'].includes(next);
  }
  if (next === 'sharing' && (current === 'student' || current === 'supertarget' || current === 'target')) {
    return true;
  }
  if (next === 'out_of_budget') {
    return ['student', 'target', 'supertarget'].includes(current);
  }
  if (next === 'short_term' && current === 'student') return true;
  if (next === 'supertarget' && current === 'student') return true;
  if (next === 'postponed' && (current === 'student' || current === 'target')) return true;
  if (next === 'ejari' && current === 'student') return true;
  return (RANK[next] || 0) > (RANK[current] || 0);
}
