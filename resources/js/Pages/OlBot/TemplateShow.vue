<script setup>
import { Link } from '@inertiajs/vue3';
import OlBotLayout from './OlBotLayout.vue';
import { useOlBotI18n } from './composables/useOlBotI18n';

defineProps({
    version: { type: Object, required: true },
    backUrl: { type: String, default: '/ol-bot/templates/main' },
});

const { t } = useOlBotI18n();
</script>

<template>
    <OlBotLayout
        :title="`${version.template_key} #${version.id}`"
        :subtitle="`${version.kind === 'tool' ? t('kindTool') : t('kindInstruction')} · ${version.is_active ? t('versionActive') : t('versionArchived')} · ${version.note || t('noNote')}`"
        active-key="ol-bot-templates"
    >
        <div class="flex justify-end">
            <Link :href="backUrl" class="ui-btn ui-btn-secondary">{{ t('back') }}</Link>
        </div>
        <pre class="ui-panel ui-scroll overflow-auto p-4 font-mono text-xs leading-5 text-ink">{{ version.body }}</pre>
    </OlBotLayout>
</template>
