<script setup>
import { computed } from 'vue';
import { useAppPreferences } from '../../../composables/useAppPreferences';

const props = defineProps({
    timeline: { type: Array, default: () => [] },
    messages: { type: Object, required: true },
    t: { type: Function, required: true },
    renderValue: { type: Function, required: true },
    showTechnicalFields: { type: Boolean, default: false },
});

const { locale } = useAppPreferences();

const technicalFields = new Set([
    'bitrix_id',
    'bitrix_created_at',
    'bitrix_updated_at',
    'last_synced_at',
    'changed_by_bitrix_user_id',
]);

const hiddenByDefaultFields = new Set([
    'contact_type_id',
]);

const alwaysHiddenFields = new Set([
    'changed_by_bitrix_user_id',
    'changed_by_bitrix_user_name',
]);

const dateLocale = computed(() => (locale.value === 'en' ? 'en-GB' : 'ru-RU'));

/**
 * @param {string} key
 * @returns {string}
 */
const fieldLabel = (key) => {
    const pack = props.messages?.[locale.value] ?? props.messages?.ru ?? {};
    return pack.fields?.[key] ?? props.messages?.ru?.fields?.[key] ?? key;
};

/**
 * @param {string} key
 * @returns {string}
 */
const eventLabel = (key) => {
    const pack = props.messages?.[locale.value] ?? props.messages?.ru ?? {};
    return pack.events?.[key] ?? props.messages?.ru?.events?.[key] ?? key;
};

const isIsoDateLike = (value) => {
    if (typeof value !== 'string') {
        return false;
    }

    return /^\d{4}-\d{2}-\d{2}(T|\s)\d{2}:\d{2}:\d{2}/.test(value) || /^\d{4}-\d{2}-\d{2}$/.test(value);
};

const isDateOnly = (value) => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value);
const isDateFieldKey = (key) => typeof key === 'string' && key.endsWith('_date');

const toDateObject = (value) => {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const raw = String(value).trim();
    if (raw === '') {
        return null;
    }

    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw)) {
        const date = new Date(`${raw.replace(' ', 'T')}Z`);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(raw)) {
        const date = new Date(`${raw}Z`);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    const date = new Date(raw);
    return Number.isNaN(date.getTime()) ? null : date;
};

const formatDate = (value) => {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const date = new Date(`${String(value)}T00:00:00`);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return new Intl.DateTimeFormat(dateLocale.value, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(date);
};

const formatDateForField = (value) => {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    if (isDateOnly(value)) {
        return formatDate(value);
    }

    const date = toDateObject(value);
    if (date === null) {
        return String(value);
    }

    return new Intl.DateTimeFormat(dateLocale.value, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(date);
};

const formatDateTime = (value) => {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const date = toDateObject(value);
    if (date === null) {
        return String(value);
    }

    return new Intl.DateTimeFormat(dateLocale.value, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).format(date);
};

const formatTime = (value) => {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const date = toDateObject(value);
    if (date === null) {
        return String(value);
    }

    return new Intl.DateTimeFormat(dateLocale.value, {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).format(date);
};

const getDayKey = (value) => {
    const date = toDateObject(value);
    if (date === null) {
        return '';
    }

    return new Intl.DateTimeFormat('sv-SE', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(date);
};

const getDayLabel = (value) => {
    const date = toDateObject(value);
    if (date === null) {
        return '';
    }

    const now = new Date();
    const todayKey = getDayKey(now);
    const yesterdayDate = new Date(now);
    yesterdayDate.setDate(now.getDate() - 1);
    const yesterdayKey = getDayKey(yesterdayDate);
    const dayKey = getDayKey(date);

    if (dayKey === todayKey) {
        return props.t(props.messages, 'today');
    }

    if (dayKey === yesterdayKey) {
        return props.t(props.messages, 'yesterday');
    }

    return new Intl.DateTimeFormat(dateLocale.value, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(date);
};

const parseValues = (value) => {
    if (value === null || value === undefined || value === '') {
        return {};
    }

    if (typeof value === 'object' && !Array.isArray(value)) {
        return value;
    }

    if (typeof value === 'string') {
        try {
            const parsed = JSON.parse(value);
            if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                return parsed;
            }
        } catch {
            return { value };
        }
    }

    return { value };
};

const normalize = (value) => {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (typeof value === 'boolean') {
        return value ? 1 : 0;
    }

    return value;
};

const display = (value, key = null) => {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? props.t(props.messages, 'yes') : props.t(props.messages, 'no');
    }

    if (typeof value === 'number') {
        return String(value);
    }

    if (Array.isArray(value) || (value && typeof value === 'object')) {
        return JSON.stringify(value);
    }

    if (isDateFieldKey(key)) {
        return formatDateForField(value);
    }

    if (isDateOnly(value)) {
        return formatDate(value);
    }

    if (isIsoDateLike(value)) {
        return formatDateTime(value);
    }

    return props.renderValue(value);
};

const buildChanges = (event) => {
    const oldValues = parseValues(event.old_values);
    const newValues = parseValues(event.new_values);

    const keys = Array.from(new Set([...Object.keys(oldValues), ...Object.keys(newValues)]));
    const visibleKeys = keys.filter((key) => {
            if (props.showTechnicalFields) {
                return true;
            }

            if (technicalFields.has(key) || hiddenByDefaultFields.has(key)) {
                return false;
            }

            return true;
        });

    const changes = visibleKeys
        .filter((key) => !alwaysHiddenFields.has(key))
        .filter((key) => normalize(oldValues[key]) !== normalize(newValues[key]))
        .filter((key) => !(key === 'is_deleted' && normalize(newValues[key]) === 0))
        .map((key) => ({
            key,
            label: fieldLabel(key),
            from: display(oldValues[key], key),
            to: display(newValues[key], key),
        }));

    if (changes.length > 0) {
        return changes;
    }

    if (visibleKeys.length === 0) {
        return [];
    }

    if (Object.keys(newValues).length > 0 || Object.keys(oldValues).length > 0) {
        return [{
            key: 'value',
            from: display(Object.keys(oldValues).length > 0 ? oldValues : null, 'value'),
            to: display(Object.keys(newValues).length > 0 ? newValues : null, 'value'),
        }];
    }

    return [];
};

const buildEventLabel = (event) => {
    const baseLabel = eventLabel(event.event);
    if (event.event !== 'bitrix.contact.updated') {
        return baseLabel;
    }

    const newValues = parseValues(event.new_values);
    const author = typeof newValues.changed_by_bitrix_user_name === 'string'
        ? newValues.changed_by_bitrix_user_name.trim()
        : '';

    return author !== '' ? `${baseLabel} (${author})` : baseLabel;
};

const items = computed(() => {
    let previousDayKey = '';

    return props.timeline.map((event) => {
        const happenedAt = event.happened_at || event.created_at;
        const dayKey = getDayKey(happenedAt);
        const showDayHeader = dayKey !== '' && dayKey !== previousDayKey;

        if (dayKey !== '') {
            previousDayKey = dayKey;
        }

        return {
            ...event,
            eventLabel: buildEventLabel(event),
            happenedLabel: formatDateTime(happenedAt),
            timeLabel: formatTime(happenedAt),
            dayLabel: getDayLabel(happenedAt),
            showDayHeader,
            changes: buildChanges(event),
        };
    });
});
</script>

<template>
    <div v-if="items.length === 0" class="ui-empty">
        {{ t(messages, 'noEvents') }}
    </div>
    <div v-else class="relative space-y-3 pl-5">
        <div class="pointer-events-none absolute bottom-1 left-2 top-1 w-px bg-gradient-to-b from-brand/50 via-stone-200 to-transparent dark:via-slate-700"></div>
        <template v-for="event in items" :key="event.id">
            <div v-if="event.showDayHeader" class="pt-1 text-[11px] font-bold uppercase tracking-[0.12em] text-stone-400 dark:text-slate-500">
                {{ event.dayLabel }}
            </div>
            <div class="relative rounded-xl border border-stone-200/90 bg-white p-3 shadow-sm dark:border-slate-700/80 dark:bg-slate-900/70">
                <span class="absolute -left-[1.05rem] top-4 h-2.5 w-2.5 rounded-full border-2 border-page bg-brand shadow-sm"></span>
                <span class="absolute -left-[0.4rem] top-[1.2rem] h-px w-[0.7rem] bg-brand/40"></span>
                <div class="mb-1 text-[11px] font-medium text-stone-400 dark:text-slate-500">{{ event.timeLabel }}</div>
                <div class="mb-2 text-sm font-semibold leading-snug text-stone-900 dark:text-slate-100">{{ event.eventLabel }}</div>
                <div class="space-y-2">
                    <div
                        v-for="change in event.changes"
                        :key="`${event.id}-${change.key}`"
                        class="rounded-lg border border-stone-100 bg-stone-50/80 px-2.5 py-2 dark:border-slate-700/70 dark:bg-slate-800/40"
                    >
                        <div class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-stone-400 dark:text-slate-500">{{ change.label }}</div>
                        <div class="grid grid-cols-[1fr_auto_1fr] items-stretch gap-2 text-xs leading-relaxed">
                            <div class="rounded-md bg-rose-50 px-2 py-1.5 text-rose-700 dark:bg-rose-950/35 dark:text-rose-300">
                                {{ change.from }}
                            </div>
                            <div class="self-center text-stone-300 dark:text-slate-600">→</div>
                            <div class="rounded-md bg-emerald-50 px-2 py-1.5 text-emerald-700 dark:bg-emerald-950/35 dark:text-emerald-300">
                                {{ change.to }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
