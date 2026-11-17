const FREE_MODELS = new Set([
    'bitrix/bitrixgpt-5.5',
    'bitrix/bitrixgpt-5.5-thinking',
    'bitrix/embeddings',
    'bitrix/bitrixgpt-6.0-diarization',
]);

const AGENT_PROMPT_PER_M = 0.889;
const AGENT_COMPLETION_PER_M = 4.446;
const PROMPT_SHARE = 0.9336;
const COMPLETION_SHARE = 0.0664;
const PAID_BLEND_PER_M =
    PROMPT_SHARE * AGENT_PROMPT_PER_M + COMPLETION_SHARE * AGENT_COMPLETION_PER_M;

/**
 * @param {string|null|undefined} model
 * @param {boolean|null|undefined} freeFlag
 * @returns {boolean}
 */
export function isFreeModel(model, freeFlag = null) {
    if (typeof freeFlag === 'boolean') return freeFlag;
    const id = String(model || '');
    const normalized = id.startsWith('bitrix/') ? id : (id ? `bitrix/${id}` : '');
    return FREE_MODELS.has(normalized) || FREE_MODELS.has(id);
}

/**
 * @param {unknown} credits tokens/1000 from ol_bot_chats
 * @param {{ model?: string|null, free?: boolean|null }} [opts]
 * @returns {string}
 */
export function formatCredits(credits, opts = {}) {
    const value = Number(credits);
    if (!Number.isFinite(value)) {
        return '—';
    }
    const model = opts.model || 'bitrix/bitrixgpt-5.5-thinking';
    const free = isFreeModel(model, opts.free);
    const tokensM = value / 1000;
    const paidUsd = tokensM * PAID_BLEND_PER_M;
    const shown = Math.abs(value - Math.round(value)) < 0.05
        ? String(Math.round(value))
        : value.toFixed(1);
    const paidShown = paidUsd < 0.01 && paidUsd > 0
        ? paidUsd.toFixed(3)
        : paidUsd.toFixed(2);
    if (free) {
        return `${shown} · free · $0 · ≈$${paidShown}`;
    }
    return `${shown} · paid · ≈$${paidShown}`;
}
