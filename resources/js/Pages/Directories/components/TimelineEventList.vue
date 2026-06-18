<script setup>
import { computed } from 'vue';

const props = defineProps({
    timeline: { type: Array, default: () => [] },
    messages: { type: Object, required: true },
    t: { type: Function, required: true },
    renderValue: { type: Function, required: true },
    showTechnicalFields: { type: Boolean, default: false },
});

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

const fieldLabels = {
    first_name: 'Имя',
    last_name: 'Фамилия',
    contact_type_id: 'Тип контакта',
    contact_type_name: 'Тип контакта',
    nationality: 'Национальность',
    gender: 'Пол',
    language: 'Язык',
    birth_date: 'Дата рождения',
    is_deleted: 'Удален',
    changed_by_bitrix_user_name: 'Изменил в Bitrix',
    changed_by_bitrix_user_id: 'ID пользователя Bitrix',
    title: 'Название',
    stage_id: 'Стадия',
    building_id: 'Билдинг',
    landlord_contact_id: 'Лендлорд',
    metro_station_id: 'Станция метро',
    apartment_type_id: 'Тип апартамента',
    internal_number: 'Внутренний номер',
    address: 'Адрес',
    property_mode: 'Режим сдачи',
    rental_type: 'Тип аренды',
    status: 'Статус',
    floor: 'Этаж',
    metro_minutes: 'Минут до метро',
    transport_type: 'Транспорт',
    parking_number: 'Парковка',
    google_maps_link: 'Google Maps',
    bathrooms: 'Ванные',
    rooms: 'Комнаты',
    area_sqm: 'Площадь',
    wifi_name: 'Wi-Fi имя',
    wifi_password: 'Wi-Fi пароль',
    access_cards: 'Карты доступа',
    parking_cards: 'Карты паркинга',
    keys_count: 'Ключи',
    lock_pass: 'Код замка',
    keybox_code: 'Код keybox',
    room_keys_notes: 'Заметки по ключам',
    apartment_id: 'Апартамент',
    unit_id: 'Юнит',
    tenant_contact_id: 'Тенант',
    co_tenant_contact_id: 'Ко-тенант',
    check_in_date: 'Дата заселения',
    check_out_date: 'Дата выселения',
    value: 'Значение',
};

const eventLabels = {
    'bitrix.contact.created': 'Контакт Bitrix создан',
    'bitrix.contact.updated': 'Контакт Bitrix обновлен',
    'bitrix.contact.deleted': 'Контакт Bitrix удален',
    'bitrix.apartment.created': 'Апартамент Bitrix создан',
    'bitrix.apartment.updated': 'Апартамент Bitrix обновлен',
    'bitrix.apartment.deleted': 'Апартамент Bitrix удален',
    'bitrix.unit.created': 'Юнит Bitrix создан',
    'bitrix.unit.updated': 'Юнит Bitrix обновлен',
    'bitrix.unit.deleted': 'Юнит Bitrix удален',
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

    return new Intl.DateTimeFormat('ru-RU', {
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

    return new Intl.DateTimeFormat('ru-RU', {
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

    return new Intl.DateTimeFormat('ru-RU', {
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

    return new Intl.DateTimeFormat('ru-RU', {
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
        return 'Сегодня';
    }

    if (dayKey === yesterdayKey) {
        return 'Вчера';
    }

    return new Intl.DateTimeFormat('ru-RU', {
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
        return value ? 'Да' : 'Нет';
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
            label: fieldLabels[key] ?? key,
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
    const baseLabel = eventLabels[event.event] ?? event.event;
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
    <div v-if="items.length === 0" class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-lg text-slate-500 dark:border-slate-600 dark:text-slate-400">
        {{ t(messages, 'noEvents') }}
    </div>
    <div v-else class="relative space-y-3 pl-6">
        <div class="pointer-events-none absolute bottom-0 left-2.5 top-0 w-px bg-slate-200 dark:bg-slate-700"></div>
        <template v-for="event in items" :key="event.id">
            <div v-if="event.showDayHeader" class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                {{ event.dayLabel }}
            </div>
            <div class="relative rounded-lg border border-slate-200 bg-slate-50/80 p-3 dark:border-slate-700 dark:bg-slate-800/50">
                <span class="absolute -left-[1.18rem] top-4 h-3 w-3 rounded-full border-2 border-white bg-slate-500 dark:border-slate-900 dark:bg-slate-300"></span>
                <span class="absolute -left-[0.44rem] top-[1.03rem] h-px w-[0.75rem] bg-slate-300 dark:bg-slate-600"></span>
                <div class="mb-1 text-xs text-slate-500 dark:text-slate-400">{{ event.timeLabel }}</div>
                <div class="mb-2 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ event.eventLabel }}</div>
                <div class="space-y-2">
                    <div
                        v-for="change in event.changes"
                        :key="`${event.id}-${change.key}`"
                        class="rounded-md border border-slate-200 bg-white/70 p-2 dark:border-slate-700 dark:bg-slate-900/40"
                    >
                        <div class="mb-1 text-xs font-medium text-slate-600 dark:text-slate-300">{{ change.label }}</div>
                        <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-1.5 text-xs">
                            <div class="rounded border border-rose-200 bg-rose-50 px-2 py-1 text-rose-700 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-300">
                                {{ change.from }}
                            </div>
                            <div class="text-slate-400">→</div>
                            <div class="rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-300">
                                {{ change.to }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
