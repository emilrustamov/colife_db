/**
 * Build Wikibot-like analysis steps from assistant message meta.
 *
 * @param {object|null|undefined} meta
 * @param {string|null|undefined} body
 * @param {number|null|undefined} responseSec
 * @returns {Array<{ type: string, title: string, duration_sec: number|null, content: unknown }>}
 */
export function buildAnalysisSteps(meta, body, responseSec) {
    if (Array.isArray(meta?.analysis) && meta.analysis.length) {
        return meta.analysis.map((step) => ({
            type: String(step?.type || 'step'),
            title: String(step?.title || step?.type || 'Step'),
            duration_sec: step?.duration_sec == null ? null : Number(step.duration_sec),
            content: step?.content ?? null,
        }));
    }

    /** @type {Array<{ type: string, title: string, duration_sec: number|null, content: unknown }>} */
    const steps = [];
    const thinking = Array.isArray(meta?.thinking)
        ? meta.thinking
        : meta?.thinking
            ? [meta.thinking]
            : [];

    thinking.forEach((text) => {
        if (!text) return;
        steps.push({
            type: 'explanation',
            title: 'Explanation of the result',
            duration_sec: null,
            content: text,
        });
    });

    if (meta?.filters && Object.keys(meta.filters).length) {
        steps.push({
            type: 'parameters',
            title: 'Parameters',
            duration_sec: null,
            content: meta.filters,
        });
    }

    const tools = Array.isArray(meta?.tools) ? meta.tools : [];
    tools.forEach((tool) => {
        steps.push({
            type: 'function_call',
            title: 'Function call',
            duration_sec: null,
            content: { name: tool?.name, args: tool?.args ?? {} },
        });
        steps.push({
            type: 'function_call_result',
            title: 'Function call result',
            duration_sec: null,
            content: tool?.result ?? null,
        });
    });

    if (body) {
        steps.push({
            type: 'dialog',
            title: 'Dialog',
            duration_sec: responseSec == null ? null : Number(responseSec),
            content: body,
        });
    }

    return steps;
}

/**
 * @param {object|null|undefined} meta
 * @returns {boolean}
 */
export function hasAnalysis(meta) {
    if (!meta) return false;
    if (Array.isArray(meta.analysis) && meta.analysis.length) return true;
    if (Array.isArray(meta.thinking) && meta.thinking.length) return true;
    if (meta.thinking) return true;
    if (Array.isArray(meta.tools) && meta.tools.length) return true;
    if (meta.filters && Object.keys(meta.filters).length) return true;
    return false;
}
