<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useOlBotI18n } from '../../composables/useOlBotI18n';

const props = defineProps({
    models: { type: Array, default: () => [] },
    active: { type: String, default: '' },
    ok: { type: Boolean, default: true },
    error: { type: String, default: null },
});

const { t } = useOlBotI18n();
const open = ref(false);
const search = ref('');
const saving = ref(false);

const activeModel = computed(() =>
    props.models.find((row) => row.id === props.active) || null,
);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.models;
    return props.models.filter((row) =>
        String(row.name || '').toLowerCase().includes(q)
        || String(row.id || '').toLowerCase().includes(q),
    );
});

/**
 * @param {object} row
 * @returns {string}
 */
const priceLabel = (row) => {
    if (row?.free) return 'free';
    const prompt = Number(row?.pricing?.prompt ?? 0);
    const completion = Number(row?.pricing?.completion ?? 0);
    const unit = row?.pricing?.unit || 'vibes';
    return `${prompt}/${completion} ${unit}`;
};

/**
 * @param {string} modelId
 */
const selectModel = (modelId) => {
    if (!modelId || modelId === props.active || saving.value) return;
    saving.value = true;
    router.post('/ol-bot/templates/model', { model: modelId }, {
        preserveScroll: true,
        onFinish: () => {
            saving.value = false;
            open.value = false;
            search.value = '';
        },
    });
};
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="ui-btn ui-btn-secondary min-w-[12rem] justify-between gap-2"
            :disabled="saving"
            @click="open = !open"
        >
            <span class="truncate text-left">
                <span class="block text-[10px] uppercase tracking-wide text-ink-muted">{{ t('aiModel') }}</span>
                <span class="block text-sm font-medium text-ink">
                    {{ activeModel?.name || active || '—' }}
                </span>
            </span>
            <span class="shrink-0 text-xs text-ink-muted">{{ activeModel ? priceLabel(activeModel) : '' }} ▾</span>
        </button>

        <div
            v-if="open"
            class="absolute right-0 z-30 mt-2 w-[min(92vw,28rem)] overflow-hidden rounded-xl border border-line bg-surface shadow-lg"
        >
            <div class="border-b border-line p-2">
                <input
                    v-model="search"
                    type="search"
                    class="ui-input w-full text-sm"
                    :placeholder="t('aiModelSearch')"
                >
            </div>
            <div v-if="!ok" class="p-3 text-sm text-ink-muted">
                {{ t('aiModelLoadError') }}: {{ error || 'unknown' }}
            </div>
            <div v-else class="ui-scroll max-h-80 overflow-y-auto">
                <button
                    v-for="row in filtered"
                    :key="row.id"
                    type="button"
                    class="flex w-full items-start justify-between gap-3 border-b border-line px-3 py-2.5 text-left last:border-b-0 hover:bg-surface-muted/70"
                    :class="row.id === active ? 'bg-brand-soft/40' : ''"
                    :disabled="saving"
                    @click="selectModel(row.id)"
                >
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-ink">{{ row.name }}</span>
                        <span class="block truncate text-[11px] text-ink-muted">{{ row.id }}</span>
                        <span v-if="row.reasoning" class="mt-0.5 inline-block text-[10px] text-ink-muted">reasoning</span>
                    </span>
                    <span class="shrink-0 text-xs text-ink-muted">{{ priceLabel(row) }}</span>
                </button>
                <div v-if="!filtered.length" class="p-3 text-sm text-ink-muted">{{ t('aiModelEmpty') }}</div>
            </div>
            <div v-if="activeModel" class="border-t border-line bg-surface-muted/50 p-3 text-xs text-ink-muted">
                <div class="font-medium text-ink">{{ activeModel.name }}</div>
                <div class="mt-1">{{ priceLabel(activeModel) }}</div>
                <div v-if="activeModel.contextLength" class="mt-1">
                    context {{ activeModel.contextLength.toLocaleString() }}
                    <span v-if="activeModel.maxOutputTokens">
                        · out {{ activeModel.maxOutputTokens.toLocaleString() }}
                    </span>
                </div>
            </div>
        </div>
        <button
            v-if="open"
            type="button"
            class="fixed inset-0 z-20 cursor-default"
            aria-label="Close model picker"
            @click="open = false"
        />
    </div>
</template>
