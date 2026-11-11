<script setup>
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import OlBotLayout from './OlBotLayout.vue';

const props = defineProps({
    active: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
    keys: { type: Array, default: () => [] },
});

const page = usePage();
const selectedKey = ref(props.active[0]?.template_key || '');
const selected = computed(() => props.active.find((item) => item.template_key === selectedKey.value) || null);

const form = useForm({
    template_key: '',
    kind: 'instruction',
    body: '',
    note: '',
});

watch(selected, (value) => {
    if (!value) return;
    form.template_key = value.template_key;
    form.kind = value.kind;
    form.body = value.body;
    form.note = '';
}, { immediate: true });

const keyHistory = computed(() =>
    props.history.filter((item) => item.template_key === selectedKey.value),
);

const save = () => {
    form.post('/ol-bot/templates');
};

/**
 * @param {number|string} id
 * @returns {void}
 */
const activate = (id) => {
    router.post(`/ol-bot/templates/${id}/activate`);
};
</script>

<template>
    <OlBotLayout
        title="Templates"
        subtitle="Versions + rollback. Active body is served to the bot."
        active-key="ol-bot-templates"
    >
        <div v-if="page.props.flash?.status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-200">
            {{ page.props.flash.status }}
        </div>

        <div class="grid gap-4 lg:grid-cols-[240px_1fr]">
            <aside class="ui-panel p-3">
                <div class="ui-nav-group-label">Templates</div>
                <button
                    v-for="item in active"
                    :key="item.id"
                    type="button"
                    class="ui-nav-item mb-1"
                    :class="item.template_key === selectedKey ? 'ui-nav-item-active bg-brand-soft text-brand' : ''"
                    @click="selectedKey = item.template_key"
                >
                    <span class="min-w-0 flex-1 truncate">
                        {{ item.template_key }}
                        <span class="mt-0.5 block text-[11px] font-medium text-ink-muted">{{ item.kind }}</span>
                    </span>
                </button>
                <p v-if="active.length === 0" class="ui-muted px-2 py-4 text-xs">No templates yet</p>
            </aside>

            <section class="space-y-4">
                <form class="ui-panel p-4" @submit.prevent="save">
                    <div class="mb-3 grid gap-3 md:grid-cols-3">
                        <div>
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-ink-muted">Key</label>
                            <input v-model="form.template_key" class="ui-input" required>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-ink-muted">Kind</label>
                            <select v-model="form.kind" class="ui-input">
                                <option value="instruction">instruction</option>
                                <option value="tool">tool</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-ink-muted">Note</label>
                            <input v-model="form.note" class="ui-input" placeholder="why this version">
                        </div>
                    </div>
                    <textarea
                        v-model="form.body"
                        rows="22"
                        class="ui-input font-mono text-xs leading-5"
                        required
                    />
                    <div class="mt-3 flex items-center gap-3">
                        <button type="submit" class="ui-btn ui-btn-primary !px-4 !py-2 !text-sm" :disabled="form.processing">
                            Save new version
                        </button>
                        <span v-if="form.errors.body" class="text-xs text-red-600 dark:text-red-400">{{ form.errors.body }}</span>
                    </div>
                </form>

                <div class="ui-panel p-4">
                    <div class="mb-3 text-sm font-semibold text-ink">History</div>
                    <div class="ui-scroll overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="text-xs uppercase tracking-wide text-ink-muted">
                                <tr>
                                    <th class="px-2 py-2">ID</th>
                                    <th class="px-2 py-2">Active</th>
                                    <th class="px-2 py-2">Note</th>
                                    <th class="px-2 py-2">Created</th>
                                    <th class="px-2 py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in keyHistory" :key="item.id" class="border-t border-line">
                                    <td class="px-2 py-2.5">
                                        <Link :href="`/ol-bot/templates/${item.id}`" class="ui-link">#{{ item.id }}</Link>
                                    </td>
                                    <td class="px-2 py-2.5">
                                        <span v-if="item.is_active" class="ui-chip bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">active</span>
                                        <span v-else class="text-ink-muted">—</span>
                                    </td>
                                    <td class="px-2 py-2.5 text-ink">{{ item.note || '—' }}</td>
                                    <td class="px-2 py-2.5 text-ink-muted">{{ item.created_at }}</td>
                                    <td class="px-2 py-2.5">
                                        <button
                                            v-if="!item.is_active"
                                            type="button"
                                            class="ui-btn ui-btn-secondary"
                                            @click="activate(item.id)"
                                        >
                                            Rollback
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="keyHistory.length === 0">
                                    <td colspan="5" class="px-2 py-8 text-center text-sm text-ink-muted">No versions</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </OlBotLayout>
</template>
