<script setup>
import { Link, router } from '@inertiajs/vue3';
import { useOlBotI18n } from '../../composables/useOlBotI18n';

const props = defineProps({
    items: { type: Array, default: () => [] },
    scope: { type: String, default: 'main' },
});

const { t, formatDateTime } = useOlBotI18n();

/**
 * @param {number|string} id
 * @returns {void}
 */
const activate = (id) => {
    router.post(`/ol-bot/templates/${id}/activate`, { scope: props.scope });
};
</script>

<template>
    <div class="ui-panel p-4">
        <h3 class="mb-3 text-sm font-semibold text-ink">{{ t('history') }}</h3>
        <div class="ui-scroll overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-2 py-2">ID</th>
                        <th class="px-2 py-2">{{ t('note') }}</th>
                        <th class="px-2 py-2">{{ t('active') }}</th>
                        <th class="px-2 py-2" />
                        <th class="px-2 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in items" :key="item.id" class="border-t border-ink-muted/10">
                        <td class="px-2 py-2">
                            <Link :href="`/ol-bot/templates/versions/${item.id}`" class="ui-link">#{{ item.id }}</Link>
                        </td>
                        <td class="px-2 py-2">{{ item.note || '—' }}</td>
                        <td class="px-2 py-2">{{ item.is_active ? t('yes') : '' }}</td>
                        <td class="px-2 py-2 text-ink-muted">{{ formatDateTime(item.created_at) }}</td>
                        <td class="px-2 py-2 text-right">
                            <button
                                v-if="!item.is_active"
                                type="button"
                                class="ui-btn ui-btn-secondary"
                                @click="activate(item.id)"
                            >
                                {{ t('activate') }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-if="items.length === 0" class="ui-muted py-3 text-xs">{{ t('noVersions') }}</p>
        </div>
    </div>
</template>
