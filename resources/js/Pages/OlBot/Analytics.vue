<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import OlBotLayout from './OlBotLayout.vue';

const props = defineProps({
    tab: { type: String, default: 'chats' },
    q: { type: String, default: null },
    page: { type: Number, default: 1 },
    chats: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    weekly: { type: Array, default: () => [] },
    catalogCount: { type: Number, default: 0 },
});

const search = ref(props.q || '');

/**
 * @param {string} tab
 * @returns {void}
 */
const goTab = (tab) => {
    router.get('/ol-bot/analytics', { tab, q: search.value || undefined }, { preserveState: true });
};

const submitSearch = () => {
    router.get('/ol-bot/analytics', { tab: 'chats', q: search.value || undefined }, { preserveState: true });
};

/**
 * @param {number} delta
 * @returns {void}
 */
const nextPage = (delta) => {
    const page = Math.max(1, props.page + delta);
    router.get('/ol-bot/analytics', { tab: 'chats', q: search.value || undefined, page }, { preserveState: true });
};

/**
 * @param {number|null|undefined} value
 * @returns {string}
 */
const fmtSec = (value) => (value == null ? '—' : `${value}s`);
</script>

<template>
    <OlBotLayout
        title="Analytics"
        :subtitle="`Catalog snapshot: ${catalogCount} apartments`"
        active-key="ol-bot-analytics"
    >
        <div class="flex gap-1.5">
            <button
                type="button"
                class="ui-btn"
                :class="tab === 'chats' ? 'ui-btn-primary' : 'ui-btn-secondary'"
                @click="goTab('chats')"
            >
                Chats
            </button>
            <button
                type="button"
                class="ui-btn"
                :class="tab === 'weekly' ? 'ui-btn-primary' : 'ui-btn-secondary'"
                @click="goTab('weekly')"
            >
                Weekly summary
            </button>
        </div>

        <div v-if="tab === 'chats'" class="space-y-3">
            <form class="flex flex-wrap gap-2" @submit.prevent="submitSearch">
                <div class="relative min-w-0 flex-1 max-w-md">
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.75"
                        class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-muted"
                        aria-hidden="true"
                    >
                        <circle cx="11" cy="11" r="7" />
                        <path stroke-linecap="round" d="m20 20-3-3" />
                    </svg>
                    <input
                        v-model="search"
                        class="ui-input !pl-9"
                        placeholder="chat / dialog / tag / reason"
                    >
                </div>
                <button type="submit" class="ui-btn ui-btn-primary">Search</button>
            </form>

            <div class="ui-panel ui-scroll overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-surface-muted text-xs uppercase tracking-wide text-ink-muted">
                        <tr>
                            <th class="px-3 py-2.5">Chat</th>
                            <th class="px-3 py-2.5">Msgs</th>
                            <th class="px-3 py-2.5">Duration</th>
                            <th class="px-3 py-2.5">1st</th>
                            <th class="px-3 py-2.5">Avg</th>
                            <th class="px-3 py-2.5">Max</th>
                            <th class="px-3 py-2.5">Esc</th>
                            <th class="px-3 py-2.5">Reason</th>
                            <th class="px-3 py-2.5">Credits</th>
                            <th class="px-3 py-2.5">Tags</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="chat in chats"
                            :key="chat.id"
                            class="border-t border-line transition hover:bg-surface-muted/60"
                        >
                            <td class="px-3 py-2.5">
                                <Link :href="`/ol-bot/analytics/chats/${chat.chat_id}`" class="ui-link">{{ chat.chat_id }}</Link>
                                <div class="text-[11px] text-ink-muted">{{ chat.started_at }}</div>
                            </td>
                            <td class="px-3 py-2.5 text-ink">{{ chat.messages_count }}</td>
                            <td class="px-3 py-2.5 text-ink">{{ fmtSec(chat.duration_sec) }}</td>
                            <td class="px-3 py-2.5 text-ink">{{ fmtSec(chat.first_response_sec) }}</td>
                            <td class="px-3 py-2.5 text-ink">{{ fmtSec(chat.avg_response_sec) }}</td>
                            <td class="px-3 py-2.5 text-ink">{{ fmtSec(chat.max_response_sec) }}</td>
                            <td class="px-3 py-2.5">
                                <span
                                    v-if="chat.escalated"
                                    class="ui-chip bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300"
                                >yes</span>
                                <span v-else class="text-ink-muted">—</span>
                            </td>
                            <td class="px-3 py-2.5">
                                <div class="text-ink">{{ chat.escalate_reason || '—' }}</div>
                                <div class="text-[11px] text-ink-muted">{{ chat.escalate_bucket || '' }}</div>
                            </td>
                            <td class="px-3 py-2.5 text-ink">{{ chat.credits }}</td>
                            <td class="px-3 py-2.5 text-ink">{{ chat.tags || chat.category || '—' }}</td>
                        </tr>
                        <tr v-if="chats.length === 0">
                            <td colspan="10" class="px-3 py-10 text-center text-sm text-ink-muted">No chats</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex items-center gap-3 text-sm text-ink-muted">
                <button type="button" class="ui-btn ui-btn-secondary" :disabled="page <= 1" @click="nextPage(-1)">Prev</button>
                <span>Page {{ page }} · {{ total }} chats</span>
                <button type="button" class="ui-btn ui-btn-secondary" :disabled="page * 50 >= total" @click="nextPage(1)">Next</button>
            </div>
        </div>

        <div v-else class="ui-panel ui-scroll overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-surface-muted text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-3 py-2.5">Week</th>
                        <th class="px-3 py-2.5">Chats</th>
                        <th class="px-3 py-2.5">Msgs</th>
                        <th class="px-3 py-2.5">Avg dur</th>
                        <th class="px-3 py-2.5">Avg 1st</th>
                        <th class="px-3 py-2.5">Esc %</th>
                        <th class="px-3 py-2.5">Unknown</th>
                        <th class="px-3 py-2.5">User req</th>
                        <th class="px-3 py-2.5">Rule</th>
                        <th class="px-3 py-2.5">Credits</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in weekly"
                        :key="row.week_start"
                        class="border-t border-line transition hover:bg-surface-muted/60"
                    >
                        <td class="px-3 py-2.5 text-ink">{{ row.week_start }}</td>
                        <td class="px-3 py-2.5 text-ink">{{ row.chats }}</td>
                        <td class="px-3 py-2.5 text-ink">{{ row.messages }}</td>
                        <td class="px-3 py-2.5 text-ink">{{ fmtSec(row.avg_duration_sec) }}</td>
                        <td class="px-3 py-2.5 text-ink">{{ fmtSec(row.avg_first_response_sec) }}</td>
                        <td class="px-3 py-2.5 text-ink">{{ row.escalated_pct }}%</td>
                        <td class="px-3 py-2.5 text-ink">{{ row.escalate_unknown }}</td>
                        <td class="px-3 py-2.5 text-ink">{{ row.escalate_user_requested }}</td>
                        <td class="px-3 py-2.5 text-ink">{{ row.escalate_rule }}</td>
                        <td class="px-3 py-2.5 text-ink">{{ row.credits }}</td>
                    </tr>
                    <tr v-if="weekly.length === 0">
                        <td colspan="10" class="px-3 py-10 text-center text-sm text-ink-muted">No weekly data</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </OlBotLayout>
</template>
