<script setup>
import { Link } from '@inertiajs/vue3';
import OlBotLayout from './OlBotLayout.vue';

defineProps({
    version: { type: Object, required: true },
});
</script>

<template>
    <OlBotLayout
        :title="`${version.template_key} #${version.id}`"
        :subtitle="`${version.kind} · ${version.is_active ? 'active' : 'archived'} · ${version.note || 'no note'}`"
        active-key="ol-bot-templates"
    >
        <div class="flex justify-end">
            <Link href="/ol-bot/templates" class="ui-btn ui-btn-secondary">Back</Link>
        </div>
        <pre class="ui-panel ui-scroll overflow-auto p-4 font-mono text-xs leading-5 text-ink">{{ version.body }}</pre>
    </OlBotLayout>
</template>
