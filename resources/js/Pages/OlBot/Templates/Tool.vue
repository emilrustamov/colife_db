<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import OlBotLayout from '../OlBotLayout.vue';
import { useOlBotI18n } from '../composables/useOlBotI18n';
import InstructionEditor from './components/InstructionEditor.vue';
import TemplatesSubnav from './components/TemplatesSubnav.vue';
import VersionHistory from './components/VersionHistory.vue';

const props = defineProps({
    tool: { type: Object, required: true },
    history: { type: Array, default: () => [] },
});

const page = usePage();
const { t, toolLabel } = useOlBotI18n();

const keyHistory = computed(() =>
    props.history.filter((item) => item.template_key === props.tool.template_key),
);
</script>

<template>
    <OlBotLayout
        :title="t('templatesTitle')"
        :subtitle="t('templatesSubtitle')"
        active-key="ol-bot-templates"
    >
        <div v-if="page.props.flash?.status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-200">
            {{ page.props.flash.status }}
        </div>

        <TemplatesSubnav section="tools" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-semibold text-ink">{{ toolLabel(tool.key) }}</h2>
            <Link href="/ol-bot/templates/tools" class="ui-btn ui-btn-secondary">{{ t('back') }}</Link>
        </div>

        <InstructionEditor
            :template-key="tool.template_key"
            kind="tool"
            :body="tool.version?.body || ''"
            :scope="tool.key"
        />

        <VersionHistory :items="keyHistory" :scope="tool.key" />
    </OlBotLayout>
</template>
