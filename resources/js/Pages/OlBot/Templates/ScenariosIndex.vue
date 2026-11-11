<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import OlBotLayout from '../OlBotLayout.vue';
import { useOlBotI18n } from '../composables/useOlBotI18n';
import TemplatesSubnav from './components/TemplatesSubnav.vue';

defineProps({
    scenarios: { type: Array, default: () => [] },
});

const page = usePage();
const { t, scenarioLabel } = useOlBotI18n();
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

        <div>
            <h2 class="text-base font-semibold text-ink">{{ t('scenariosTitle') }}</h2>
            <p class="ui-muted mt-1 text-sm">{{ t('scenariosHint') }}</p>
        </div>

        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="item in scenarios"
                :key="item.scope_key"
                :href="`/ol-bot/templates/scenarios/${item.name}`"
                class="ui-panel block px-4 py-3 transition hover:border-brand/40"
            >
                <div class="text-sm font-semibold text-ink">{{ scenarioLabel(item.name) }}</div>
                <div class="ui-muted mt-1 text-xs">{{ t('functionsCount') }} {{ item.functions_count }}</div>
            </Link>
        </div>
    </OlBotLayout>
</template>
