<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import OlBotLayout from '../OlBotLayout.vue';
import { useOlBotI18n } from '../composables/useOlBotI18n';
import FunctionsPicker from './components/FunctionsPicker.vue';
import InstructionEditor from './components/InstructionEditor.vue';
import TemplatesSubnav from './components/TemplatesSubnav.vue';
import VersionHistory from './components/VersionHistory.vue';

const props = defineProps({
    scope: { type: Object, required: true },
    toolCatalog: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
});

const page = usePage();
const { t, scenarioLabel } = useOlBotI18n();

const keyHistory = computed(() =>
    props.history.filter((item) => item.template_key === props.scope.template_key),
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

        <TemplatesSubnav section="scenarios" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-base font-semibold text-ink">{{ scenarioLabel(scope.name) }}</h2>
                <span class="rounded-full bg-ink-muted/10 px-2.5 py-1 text-xs font-medium text-ink-muted">
                    {{ t('functionsCount') }} {{ scope.functions_count || 0 }}
                </span>
            </div>
            <Link href="/ol-bot/templates/scenarios" class="ui-btn ui-btn-secondary">{{ t('back') }}</Link>
        </div>

        <InstructionEditor
            :template-key="scope.template_key"
            kind="instruction"
            :body="scope.version?.body || ''"
            :scope="scope.scope_key"
        />

        <FunctionsPicker
            :scope-key="scope.scope_key"
            :tool-catalog="toolCatalog"
            :selected="scope.tool_keys || []"
        />

        <VersionHistory :items="keyHistory" :scope="scope.scope_key" />
    </OlBotLayout>
</template>
