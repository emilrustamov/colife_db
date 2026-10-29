import { refreshCatalogFromApi } from './catalog.js';

/**
 * @returns {Promise<object>}
 */
export async function refreshCatalogKb() {
  const result = await refreshCatalogFromApi();
  return {
    ok: result.ok,
    path: result.path,
    chars: result.count || 0,
    urls: ['tilda://storepart/159370973241', 'tilda://storepart/102753062141'],
    updatedAt: result.updatedAt,
    error: result.error,
    count: result.count,
    filters: result.filters,
  };
}
