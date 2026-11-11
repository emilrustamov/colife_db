<script setup>
import { Link } from '@inertiajs/vue3';
import { useOlBotI18n } from '../../composables/useOlBotI18n';

defineProps({
    section: {
        type: String,
        required: true,
    },
});

const { t } = useOlBotI18n();

const items = [
    { id: 'main', href: '/ol-bot/templates/main', labelKey: 'navMain' },
    { id: 'scenarios', href: '/ol-bot/templates/scenarios', labelKey: 'navScenarios' },
    { id: 'skills', href: '/ol-bot/templates/skills', labelKey: 'navSkills' },
    { id: 'tools', href: '/ol-bot/templates/tools', labelKey: 'navTools' },
];
</script>

<template>
    <div class="flex flex-wrap gap-1.5">
        <Link
            v-for="item in items"
            :key="item.id"
            :href="item.href"
            class="ui-btn"
            :class="section === item.id ? 'ui-btn-primary' : 'ui-btn-secondary'"
        >
            {{ t(item.labelKey) }}
        </Link>
    </div>
</template>
