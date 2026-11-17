<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import OlBotLayout from './OlBotLayout.vue';
import ResponseAnalysisDrawer from './components/ResponseAnalysisDrawer.vue';
import { useOlBotI18n } from './composables/useOlBotI18n';
import { buildAnalysisSteps, hasAnalysis } from './utils/analysis';
import { formatCredits } from './utils/credits';

const props = defineProps({
    chat: { type: Object, required: true },
    messages: { type: Array, default: () => [] },
});

const { t, formatDateTime } = useOlBotI18n();
const page = usePage();

const analysisOpen = ref(false);
const analysisSteps = ref([]);
const analysisTotalSec = ref(null);
const analysisTitle = ref('Analysis');

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

const chatModel = computed(() => {
    for (let i = rows.value.length - 1; i >= 0; i -= 1) {
        const role = String(rows.value[i]?.role || '').toLowerCase();
        if (role !== 'assistant' && role !== 'bot') continue;
        const model = rows.value[i]?.meta?.model;
        if (model) return String(model);
    }
    return null;
});

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

/**
 * @param {object} msg
 */
const openAnalysis = (msg) => {
    analysisSteps.value = buildAnalysisSteps(msg.meta, msg.body, msg.response_sec);
    analysisTotalSec.value = msg.response_sec == null ? null : Number(msg.response_sec);
    analysisTitle.value = 'Analysis';
    analysisOpen.value = true;
};

const openChatAnalysis = () => {
    const assistant = [...rows.value].reverse().find((msg) => {
        const role = String(msg.role || '').toLowerCase();
        return (role === 'assistant' || role === 'bot') && hasAnalysis(msg.meta);
    });
    if (assistant) {
        openAnalysis(assistant);
        return;
    }
    analysisSteps.value = [];
    analysisTotalSec.value = props.chat?.duration_sec == null ? null : Number(props.chat.duration_sec);
    analysisTitle.value = 'Analysis';
    analysisOpen.value = true;
};

onMounted(() => {
    const url = new URL(String(page.url || window.location.href), window.location.origin);
    if (url.searchParams.get('analysis') === '1') {
        openChatAnalysis();
    }
});
</script>

<template>
    <OlBotLayout
        :title="`Chat ${chat.chat_id}`"
        :subtitle="`${formatDateTime(chat.started_at)} · msgs ${chat.messages_count} · esc ${chat.escalated ? 'yes' : 'no'} · ${formatCredits(chat.credits, { model: chatModel })} · ${chat.tags || chat.category || '—'}`"
        active-key="ol-bot-analytics"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <a
                    v-if="chat.open_line_url"
                    :href="chat.open_line_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="ui-btn ui-btn-secondary"
                >Open Line</a>
                <a
                    v-if="chat.deal_url"
                    :href="chat.deal_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="ui-btn ui-btn-secondary"
                >Deal {{ chat.deal_id }}</a>
                <button type="button" class="ui-btn ui-btn-secondary" @click="openChatAnalysis">
                    {{ t('responseAnalysis') }}
                </button>
                <span v-if="!chat.open_line_url && !chat.deal_url" class="text-ink-muted">No Bitrix links</span>
            </div>
            <Link href="/ol-bot/analytics" class="ui-btn ui-btn-secondary">{{ t('backToAnalytics') }}</Link>
        </div>

        <div class="space-y-3">
            <div
                v-for="msg in rows"
                :key="msg.id"
                class="ui-panel p-4 text-sm"
            >
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <span class="ui-chip" :class="roleClass(msg.role)">{{ msg.role }}</span>
                    <span class="text-xs text-ink-muted">{{ formatDateTime(msg.created_at) }}</span>
                    <span
                        v-if="msg.response_sec != null"
                        class="text-xs text-ink-muted"
                    >{{ msg.response_sec }}s</span>
                    <span
                        v-if="msg.meta?.model"
                        class="text-[11px] text-ink-muted"
                    >{{ msg.meta.model }}</span>
                    <button
                        v-if="(msg.role === 'assistant' || msg.role === 'bot') && hasAnalysis(msg.meta)"
                        type="button"
                        class="ui-link ml-auto text-xs"
                        @click="openAnalysis(msg)"
                    >{{ t('responseAnalysis') }}</button>
                </div>
                <pre v-if="msg.body" class="whitespace-pre-wrap font-sans leading-relaxed text-ink">{{ msg.body }}</pre>
                <div v-else class="text-xs italic text-ink-muted">(no client text)</div>
            </div>
            <div v-if="rows.length === 0" class="ui-empty">No messages</div>
        </div>

        <ResponseAnalysisDrawer
            :open="analysisOpen"
            :title="analysisTitle"
            :steps="analysisSteps"
            :total-sec="analysisTotalSec"
            @close="analysisOpen = false"
        />
    </OlBotLayout>
</template>
