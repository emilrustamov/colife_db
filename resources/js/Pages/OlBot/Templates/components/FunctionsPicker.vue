<script setup>
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useOlBotI18n } from '../../composables/useOlBotI18n';

const props = defineProps({
    scopeKey: { type: String, required: true },
    toolCatalog: { type: Array, default: () => [] },
    selected: { type: Array, default: () => [] },
});

const { t, toolLabel } = useOlBotI18n();

const form = useForm({
    scope_key: props.scopeKey,
    tool_keys: [...props.selected],
});

watch(
    () => [props.scopeKey, props.selected],
    () => {
        form.scope_key = props.scopeKey;
        form.tool_keys = [...props.selected];
    },
    { deep: true },
);

/**
 * @param {string} tool
 * @returns {void}
 */
const toggleTool = (tool) => {
    const list = [...form.tool_keys];
    const idx = list.indexOf(tool);
    if (idx >= 0) {
        list.splice(idx, 1);
    } else {
        list.push(tool);
    }
    form.tool_keys = list;
};

/**
 * @returns {void}
 */
const save = () => {
    form.post('/ol-bot/templates/scope-tools');
};
</script>

<template>
    <div class="ui-panel p-4">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-ink">{{ t('functions') }}</h3>
                <p class="ui-muted text-xs">{{ t('functionsHint') }}</p>
            </div>
            <button
                type="button"
                class="ui-btn ui-btn-primary !px-3 !py-1.5 !text-sm"
                :disabled="form.processing"
                @click="save"
            >
                {{ t('saveFunctions') }}
            </button>
        </div>
        <div class="flex flex-wrap gap-2">
            <label
                v-for="tool in toolCatalog"
                :key="tool"
                class="inline-flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-1.5 text-xs font-medium transition"
                :class="form.tool_keys.includes(tool)
                    ? 'border-brand bg-brand-soft text-brand'
                    : 'border-ink-muted/20 text-ink-muted hover:border-ink-muted/40'"
            >
                <input
                    type="checkbox"
                    class="sr-only"
                    :checked="form.tool_keys.includes(tool)"
                    @change="toggleTool(tool)"
                >
                <span
                    class="flex h-3.5 w-3.5 items-center justify-center rounded border"
                    :class="form.tool_keys.includes(tool) ? 'border-brand bg-brand text-white' : 'border-ink-muted/40'"
                    aria-hidden="true"
                >
                    <svg
                        v-if="form.tool_keys.includes(tool)"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 16 16"
                        class="h-2.5 w-2.5"
                        fill="currentColor"
                    >
                        <path d="M6.2 11.4 2.8 8l1.1-1.1 2.3 2.3 5-5L12.3 5.3z" />
                    </svg>
                </span>
                {{ toolLabel(tool) }}
            </label>
        </div>
    </div>
</template>
