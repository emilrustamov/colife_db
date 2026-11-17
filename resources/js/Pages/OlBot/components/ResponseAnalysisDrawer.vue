<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Analysis' },
    steps: { type: Array, default: () => [] },
    totalSec: { type: [Number, null], default: null },
});

const emit = defineEmits(['close']);

/** @type {import('vue').Ref<Set<number>>} */
const openIdx = ref(new Set());

watch(
    () => [props.open, props.steps],
    () => {
        const next = new Set();
        props.steps.forEach((step, idx) => {
            if (step?.type === 'explanation' || step?.type === 'function_call_result' || step?.type === 'kb_search') {
                next.add(idx);
            }
        });
        if (next.size === 0 && props.steps.length) next.add(0);
        openIdx.value = next;
    },
    { immediate: true },
);

const totalLabel = computed(() => {
    if (props.totalSec == null) return null;
    return formatSec(props.totalSec);
});

/**
 * @param {number|null|undefined} value
 * @returns {string}
 */
function formatSec(value) {
    if (value == null || Number.isNaN(Number(value))) return '';
    const n = Number(value);
    return Number.isInteger(n) ? `${n} sec` : `${n.toFixed(2)} sec`;
}

/**
 * @param {unknown} content
 * @returns {string}
 */
function formatContent(content) {
    if (content == null || content === '') return '—';
    if (typeof content === 'string') return content;
    try {
        return JSON.stringify(content, null, 2);
    } catch {
        return String(content);
    }
}

/**
 * @param {number} idx
 */
function toggle(idx) {
    const next = new Set(openIdx.value);
    if (next.has(idx)) next.delete(idx);
    else next.add(idx);
    openIdx.value = next;
}
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="ui-overlay z-[80]"
                aria-hidden="true"
                @click.self="emit('close')"
            />
        </Transition>
        <Transition
            enter-active-class="transition-transform duration-300 ease-out"
            enter-from-class="translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transition-transform duration-250 ease-in"
            leave-from-class="translate-x-0"
            leave-to-class="translate-x-full"
        >
            <aside
                v-if="open"
                class="fixed right-0 top-0 z-[90] flex h-full w-[min(96vw,36rem)] flex-col border-l border-line bg-surface shadow-[-12px_0_40px_rgba(28,25,23,0.12)] dark:shadow-[-12px_0_40px_rgba(0,0,0,0.45)]"
                role="dialog"
                aria-modal="true"
                :aria-label="title"
            >
                <div class="flex shrink-0 items-center justify-between gap-3 border-b border-line px-4 py-3.5">
                    <div class="min-w-0">
                        <h2 class="truncate text-lg font-bold tracking-tight text-ink">{{ title }}</h2>
                        <p v-if="totalLabel" class="text-xs text-ink-muted">Total {{ totalLabel }}</p>
                    </div>
                    <button type="button" class="ui-btn ui-btn-secondary shrink-0" @click="emit('close')">
                        Close
                    </button>
                </div>

                <div class="ui-scroll flex-1 overflow-y-auto px-3 py-3">
                    <div v-if="!steps.length" class="ui-empty text-sm">No analysis for this response</div>
                    <div
                        v-for="(step, idx) in steps"
                        :key="idx"
                        class="border-b border-line last:border-b-0"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-3 px-1 py-3 text-left"
                            @click="toggle(idx)"
                        >
                            <span class="min-w-0 text-sm font-medium text-ink">
                                <span class="mr-1 text-ink-muted">{{ openIdx.has(idx) ? '▾' : '▸' }}</span>
                                {{ step.title || step.type || 'Step' }}
                            </span>
                            <span v-if="step.duration_sec != null" class="shrink-0 text-xs text-ink-muted">
                                {{ formatSec(step.duration_sec) }}
                            </span>
                        </button>
                        <div
                            v-if="openIdx.has(idx)"
                            class="mb-3 rounded-lg border border-line bg-surface-muted/60 p-3"
                        >
                            <pre class="ui-scroll max-h-[28rem] overflow-auto whitespace-pre-wrap break-words font-mono text-[11px] leading-4 text-ink">{{ formatContent(step.content) }}</pre>
                        </div>
                    </div>
                </div>

                <div class="shrink-0 border-t border-line px-4 py-2.5 text-[11px] text-ink-muted">
                    Thinking / tools / timings for this bot reply
                </div>
            </aside>
        </Transition>
    </Teleport>
</template>
