<script setup>
import { Head } from '@inertiajs/vue3';
import AdminShell from '../../Layouts/AdminShell.vue';

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    activeKey: { type: String, default: 'ol-bot-analytics' },
});
</script>

<template>
    <AdminShell :active-key="activeKey">
        <Head :title="title" />
        <div class="space-y-4">
            <div>
                <h1 class="ui-section-title">{{ title }}</h1>
                <p v-if="subtitle" class="ui-muted mt-1">{{ subtitle }}</p>
            </div>
            <slot />
        </div>
    </AdminShell>
</template>
