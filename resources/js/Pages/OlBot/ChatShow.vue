<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import OlBotLayout from './OlBotLayout.vue';

const props = defineProps({
    chat: { type: Object, required: true },
    messages: { type: Array, default: () => [] },
});

const rows = computed(() =>
    props.messages.map((msg) => {
        let meta = msg.meta_json;
        if (typeof meta === 'string') {
            try {
                meta = JSON.parse(meta);
            } catch {
                meta = null;
            }
        }
        return { ...msg, meta };
    }),
);

/**
 * @param {string} role
 * @returns {string}
 */
const roleClass = (role) => {
    const value = String(role || '').toLowerCase();
    if (value === 'user' || value === 'client') {
        return 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300';
    }
    if (value === 'assistant' || value === 'bot') {
        return 'bg-brand-soft text-brand';
    }
    return 'bg-surface-muted text-ink-muted';
};
</script>

<template>
    <OlBotLayout
        :title="`Chat ${chat.chat_id}`"
        :subtitle="`${chat.started_at} · msgs ${chat.messages_count} · esc ${chat.escalated ? 'yes' : 'no'} · ${chat.tags || chat.category || '—'}`"
        active-key="ol-bot-analytics"
    >
        <div class="flex justify-end">
            <Link href="/ol-bot/analytics" class="ui-btn ui-btn-secondary">Back</Link>
        </div>

        <div class="space-y-3">
            <div
                v-for="msg in rows"
                :key="msg.id"
                class="ui-panel p-4 text-sm"
            >
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <span class="ui-chip" :class="roleClass(msg.role)">{{ msg.role }}</span>
                    <span class="text-xs text-ink-muted">{{ msg.created_at }}</span>
                </div>
                <pre v-if="msg.body" class="whitespace-pre-wrap font-sans leading-relaxed text-ink">{{ msg.body }}</pre>
                <div v-else class="text-xs italic text-ink-muted">(no client text)</div>
                <details v-if="msg.meta" class="mt-3 rounded-xl border border-line bg-surface-muted/70 p-2.5 text-xs">
                    <summary class="cursor-pointer font-medium text-ink-muted">meta / tools / thinking</summary>
                    <pre class="ui-scroll mt-2 overflow-auto whitespace-pre-wrap text-[11px] leading-4 text-ink">{{ JSON.stringify(msg.meta, null, 2) }}</pre>
                </details>
            </div>
            <div v-if="rows.length === 0" class="ui-empty">No messages</div>
        </div>
    </OlBotLayout>
</template>
