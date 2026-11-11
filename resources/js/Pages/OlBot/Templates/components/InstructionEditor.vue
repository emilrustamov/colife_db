<script setup>
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useOlBotI18n } from '../../composables/useOlBotI18n';

const props = defineProps({
    templateKey: { type: String, required: true },
    kind: { type: String, default: 'instruction' },
    body: { type: String, default: '' },
    scope: { type: String, default: 'main' },
});

const { t } = useOlBotI18n();

const form = useForm({
    template_key: props.templateKey,
    kind: props.kind,
    body: props.body,
    note: '',
    scope: props.scope,
});

watch(
    () => [props.templateKey, props.kind, props.body, props.scope],
    () => {
        form.template_key = props.templateKey;
        form.kind = props.kind;
        form.body = props.body || '';
        form.note = '';
        form.scope = props.scope;
    },
);

/**
 * @returns {void}
 */
const save = () => {
    form.post('/ol-bot/templates');
};
</script>

<template>
    <form class="ui-panel space-y-3 p-4" @submit.prevent="save">
        <div class="grid gap-3 md:grid-cols-2">
            <div>
                <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('key') }}</label>
                <input v-model="form.template_key" class="ui-input" required readonly>
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('note') }}</label>
                <input v-model="form.note" class="ui-input" :placeholder="t('notePlaceholder')">
            </div>
        </div>
        <div>
            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-ink-muted">
                {{ kind === 'tool' ? t('schemaJson') : t('instruction') }}
            </label>
            <textarea
                v-model="form.body"
                rows="18"
                class="ui-input font-mono text-xs leading-5"
                required
            />
        </div>
        <div class="flex justify-end">
            <button type="submit" class="ui-btn ui-btn-primary !px-4 !py-2 !text-sm" :disabled="form.processing">
                {{ t('saveVersion') }}
            </button>
        </div>
    </form>
</template>
