<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useAppPreferences } from '../../composables/useAppPreferences';

const props = defineProps({
    directories: {
        type: Array,
        default: () => [],
    },
});

const menu = ref(props.directories);
const selectedKey = ref(null);
const hiddenKeys = ref([]);
const settingsOpen = ref(false);
const hiddenKeysStorageKey = 'colife.directories.hiddenKeys';
const selectedId = ref(null);
const loading = ref(false);
const query = ref('');
const rows = ref([]);
const fields = ref([]);
const form = ref({});
const timeline = ref([]);
const { locale, theme, initLocale, initTheme, toggleLocale, toggleTheme, t } = useAppPreferences();

const messages = {
    ru: {
        directories: 'Справочники',
        rows: 'строк',
        loading: 'Загрузка...',
        logout: 'Выйти',
        search: 'Поиск по строкам...',
        empty: 'Данных нет',
        card: 'Карточка',
        select: 'Выберите строку в таблице',
        timeline: 'Таймлайн',
        noEvents: 'Событий пока нет',
        old: 'было',
        new: 'стало',
        lang: 'Язык',
        theme: 'Тема',
        dark: 'Темная',
        light: 'Светлая',
        menuSettings: 'Настройки меню',
        showItem: 'Показывать',
        users: 'Пользователи',
        'contact-types': 'Типы контактов',
        contacts: 'Контакты',
        'metro-stations': 'Станции метро',
        'apartment-types': 'Типы квартир',
        pipelines: 'Конвейеры',
        stages: 'Этапы',
        buildings: 'Здания',
        apartments: 'Квартиры',
        units: 'Юниты',
        'unit-stays': 'Проживания',
    },
    en: {
        directories: 'Directories',
        rows: 'rows',
        loading: 'Loading...',
        logout: 'Logout',
        search: 'Search in rows...',
        empty: 'No data',
        card: 'Card',
        select: 'Select a row in the table',
        timeline: 'Timeline',
        noEvents: 'No events yet',
        old: 'old',
        new: 'new',
        lang: 'Language',
        theme: 'Theme',
        dark: 'Dark',
        light: 'Light',
        menuSettings: 'Menu settings',
        showItem: 'Show',
        users: 'Users',
        'contact-types': 'Contact Types',
        contacts: 'Contacts',
        'metro-stations': 'Metro Stations',
        'apartment-types': 'Apartment Types',
        pipelines: 'Pipelines',
        stages: 'Stages',
        buildings: 'Buildings',
        apartments: 'Apartments',
        units: 'Units',
        'unit-stays': 'Unit Stays',
    },
};

const translateMenuTitle = (key, fallback) => {
    const translated = t(messages, key);
    return translated === key ? fallback : translated;
};

const current = computed(() => menu.value.find((item) => item.key === selectedKey.value) ?? null);
const visibleMenu = computed(() => menu.value.filter((item) => !hiddenKeys.value.includes(item.key)));
const contactChildrenKeys = ['contact-phones', 'contact-emails'];
const visibleTopLevelMenu = computed(() => visibleMenu.value.filter((item) => !contactChildrenKeys.includes(item.key)));
const visibleContactsChildren = computed(() => visibleMenu.value.filter((item) => contactChildrenKeys.includes(item.key)));
const contactsExpanded = ref(false);
const isContactsChildSelected = computed(() => contactChildrenKeys.includes(selectedKey.value));
const visibleFields = computed(() => fields.value.slice(0, 8));

const filteredRows = computed(() => {
    const q = query.value.trim().toLowerCase();

    if (!q) {
        return rows.value;
    }

    return rows.value.filter((row) => {
        return Object.values(row).some((value) => renderValue(value).toLowerCase().includes(q));
    });
});

const loadHiddenKeys = () => {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        const raw = window.localStorage.getItem(hiddenKeysStorageKey);
        const parsed = raw ? JSON.parse(raw) : [];
        hiddenKeys.value = Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        hiddenKeys.value = [];
    }
};

const syncSelectedKey = () => {
    const visible = visibleMenu.value;
    if (!visible.some((item) => item.key === selectedKey.value)) {
        selectedKey.value = visible[0]?.key ?? null;
    }
};

const loadList = async () => {
    if (!current.value) {
        selectedId.value = null;
        query.value = '';
        form.value = {};
        timeline.value = [];
        rows.value = [];
        fields.value = [];
        return;
    }

    loading.value = true;
    selectedId.value = null;
    query.value = '';
    form.value = {};
    timeline.value = [];

    try {
        const { data } = await window.axios.get(`/api/directories/${current.value.key}`);
        rows.value = data.rows ?? [];
        fields.value = rows.value.length > 0 ? Object.keys(rows.value[0]) : [];
    } finally {
        loading.value = false;
    }
};

const selectDirectory = (key, toggleContactsExpansion = false) => {
    selectedKey.value = key;
    if (toggleContactsExpansion) {
        contactsExpanded.value = !contactsExpanded.value;
    }
    loadList();
};

const selectRow = async (rowId) => {
    if (!current.value || !rowId) {
        return;
    }

    loading.value = true;
    selectedId.value = rowId;

    try {
        const { data } = await window.axios.get(`/api/directories/${current.value.key}/${rowId}`);
        form.value = { ...(data.row ?? {}) };
        timeline.value = data.timeline ?? [];
    } finally {
        loading.value = false;
    }
};

const renderValue = (value) => {
    if (value === null || value === undefined) {
        return '';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value, null, 0);
    }

    return String(value);
};

const logout = () => {
    router.post('/logout');
};

const setItemVisible = (key, visible) => {
    const prevSelectedKey = selectedKey.value;

    if (visible) {
        hiddenKeys.value = hiddenKeys.value.filter((itemKey) => itemKey !== key);
    } else if (!hiddenKeys.value.includes(key)) {
        hiddenKeys.value = [...hiddenKeys.value, key];
    }

    window.localStorage.setItem(hiddenKeysStorageKey, JSON.stringify(hiddenKeys.value));

    syncSelectedKey();

    if (selectedKey.value !== prevSelectedKey) {
        loadList();
    }
};

onMounted(() => {
    initLocale();
    initTheme();
    loadHiddenKeys();
    syncSelectedKey();
    loadList();
});

watch(
    () => props.directories,
    (value) => {
        menu.value = value;
        const prevSelectedKey = selectedKey.value;
        syncSelectedKey();
        if (selectedKey.value !== prevSelectedKey) {
            loadList();
        }
    },
);
</script>

<template>
    <div class="min-h-screen bg-slate-100 p-4 md:p-6 dark:bg-slate-950">
        <div class="mx-auto max-w-[1700px] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <div class="grid min-h-[calc(100vh-3rem)] grid-cols-1 lg:grid-cols-[260px_1fr_380px]">
                <aside class="border-b border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900 lg:border-r lg:border-b-0">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h2 class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500 dark:text-slate-400">{{ t(messages, 'directories') }}</h2>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg border border-slate-300 p-2 text-slate-700 transition hover:bg-slate-100 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                            :aria-label="t(messages, 'menuSettings')"
                            @click="settingsOpen = !settingsOpen"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11.84 1.795a.75.75 0 0 1 1.32 0l.854 1.393a.75.75 0 0 0 .627.336l1.62-.01a.75.75 0 0 1 .744.98l-.564 1.5a.75.75 0 0 0 .18.78l1.17 1.17a.75.75 0 0 1-.29 1.26l-1.5.56a.75.75 0 0 0-.49.63l-.01 1.62a.75.75 0 0 1-.98.744l-1.5-.564a.75.75 0 0 0-.78.18l-1.17 1.17a.75.75 0 0 1-1.26-.29l-.56-1.5a.75.75 0 0 0-.63-.49l-1.62-.01a.75.75 0 0 1-.744-.98l.564-1.5a.75.75 0 0 0-.18-.78l-1.17-1.17a.75.75 0 0 1 .29-1.26l1.5-.56a.75.75 0 0 0 .49-.63l.01-1.62a.75.75 0 0 1 .98-.744l1.5.564a.75.75 0 0 0 .78-.18l1.17-1.17a.75.75 0 0 1 .94-.12Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 12m-3 3a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" />
                            </svg>
                        </button>
                    </div>

                    <div
                        v-if="settingsOpen"
                        class="mb-4 rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900"
                    >
                        <div class="max-h-52 space-y-2 overflow-auto">
                            <label
                                v-for="item in menu"
                                :key="item.key"
                                class="flex cursor-pointer items-center gap-2 text-sm text-slate-700 dark:text-slate-200"
                            >
                                <input
                                    type="checkbox"
                                    :checked="!hiddenKeys.includes(item.key)"
                                    @change="setItemVisible(item.key, $event.target.checked)"
                                >
                                <span v-if="item.icon" class="inline-flex items-center justify-center">{{ item.icon }}</span>
                                <span>{{ translateMenuTitle(item.key, item.title) }}</span>
                            </label>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <div v-for="item in visibleTopLevelMenu" :key="item.key">
                            <button
                                type="button"
                                class="w-full rounded-lg px-3 py-2.5 text-left text-sm font-medium transition"
                                :class="selectedKey === item.key ? 'bg-slate-900 text-white shadow-sm dark:bg-slate-100 dark:text-slate-900' : 'text-slate-700 hover:bg-white hover:shadow-sm dark:text-slate-200 dark:hover:bg-slate-800'"
                                @click="selectDirectory(item.key, item.key === 'contacts')"
                            >
                                <span v-if="item.icon" class="mr-2 inline-flex items-center justify-center text-base leading-none">{{ item.icon }}</span>
                                <span>{{ translateMenuTitle(item.key, item.title) }}</span>
                            </button>

                            <div
                                v-if="item.key === 'contacts' && (contactsExpanded || isContactsChildSelected)"
                                class="mt-1 space-y-1 pl-4"
                            >
                                <button
                                    v-for="child in visibleContactsChildren"
                                    :key="child.key"
                                    type="button"
                                    class="w-full rounded-lg px-3 py-2 text-left text-sm font-medium transition"
                                    :class="selectedKey === child.key ? 'bg-slate-900 text-white shadow-sm dark:bg-slate-100 dark:text-slate-900' : 'text-slate-700 hover:bg-white hover:shadow-sm dark:text-slate-200 dark:hover:bg-slate-800'"
                                    @click="selectDirectory(child.key)"
                                >
                                    <span v-if="child.icon" class="mr-2 inline-flex items-center justify-center text-base leading-none">{{ child.icon }}</span>
                                        <span>{{ translateMenuTitle(child.key, child.title) }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </aside>

                <section class="border-b border-slate-200 p-4 dark:border-slate-700 lg:border-r lg:border-b-0">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">{{ current?.title ?? 'Directory' }}</h1>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ filteredRows.length }} {{ t(messages, 'rows') }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                                @click="toggleLocale"
                            >
                                {{ t(messages, 'lang') }}: {{ locale.toUpperCase() }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-lg border border-slate-300 px-2.5 py-1.5 text-slate-700 transition hover:bg-slate-100 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                                @click="toggleTheme"
                                :aria-label="theme === 'dark' ? t(messages, 'light') : t(messages, 'dark')"
                            >
                                <svg
                                    v-if="theme === 'dark'"
                                    xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    class="h-4 w-4 text-yellow-500 dark:text-yellow-400"
                                    aria-hidden="true"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 2v2" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 20v2" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.93 4.93l1.41 1.41" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17.66 17.66l1.41 1.41" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2 12h2" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 12h2" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.93 19.07l1.41-1.41" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17.66 6.34l1.41-1.41" />
                                    <circle cx="12" cy="12" r="4" />
                                </svg>
                                <svg
                                    v-else
                                    xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    class="h-4 w-4"
                                    aria-hidden="true"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M21 12.79A9 9 0 1 1 11.21 3a7 7 0 0 0 9.79 9.79Z"
                                    />
                                </svg>
                            </button>
                            <span v-if="loading" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t(messages, 'loading') }}</span>
                            <button
                                type="button"
                                class="rounded-lg border border-red-600 bg-red-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-red-700 dark:border-red-500 dark:bg-red-500 dark:hover:bg-red-600"
                                @click="logout"
                            >
                                {{ t(messages, 'logout') }}
                            </button>
                        </div>
                    </div>

                    <div class="mb-4">
                        <input
                            v-model="query"
                            type="text"
                            :placeholder="t(messages, 'search')"
                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none ring-blue-500 focus:ring-2 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                        >
                    </div>

                    <div class="overflow-auto rounded-lg border border-slate-200 dark:border-slate-700">
                        <table class="min-w-full text-sm">
                            <thead class="sticky top-0 bg-slate-50 dark:bg-slate-800">
                                <tr>
                                    <th
                                        v-for="field in visibleFields"
                                        :key="field"
                                        class="px-3 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-300"
                                    >
                                        {{ field }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in filteredRows"
                                    :key="row.id"
                                    class="cursor-pointer border-t border-slate-100 transition hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/50"
                                    :class="selectedId === row.id ? 'bg-blue-50/70 dark:bg-blue-900/30' : ''"
                                    @click="selectRow(row.id)"
                                >
                                    <td v-for="field in visibleFields" :key="`${row.id}-${field}`" class="max-w-[220px] truncate px-3 py-2.5 text-slate-700 dark:text-slate-200">
                                        {{ renderValue(row[field]) }}
                                    </td>
                                </tr>
                                <tr v-if="filteredRows.length === 0 && !loading">
                                    <td :colspan="Math.max(visibleFields.length, 1)" class="px-3 py-8 text-center text-sm text-slate-500 dark:text-slate-400">
                                        {{ t(messages, 'empty') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="p-4">
                    <h3 class="mb-3 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500 dark:text-slate-400">{{ t(messages, 'card') }}</h3>
                    <div class="mb-4 space-y-3 rounded-lg border border-slate-200 bg-slate-50/60 p-3 dark:border-slate-700 dark:bg-slate-800/40">
                        <div v-if="Object.keys(form).length === 0" class="text-sm text-slate-500 dark:text-slate-400">
                            {{ t(messages, 'select') }}
                        </div>
                        <div v-else class="max-h-[320px] space-y-3 overflow-auto pr-1">
                            <div v-for="(value, key) in form" :key="key">
                                <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-300">{{ key }}</label>
                                <input :value="renderValue(value)" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100" readonly>
                            </div>
                        </div>
                    </div>

                    <h3 class="mb-3 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500 dark:text-slate-400">{{ t(messages, 'timeline') }}</h3>
                    <div class="max-h-[420px] space-y-3 overflow-auto rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <div v-if="timeline.length === 0" class="text-sm text-slate-500 dark:text-slate-400">
                            {{ t(messages, 'noEvents') }}
                        </div>
                        <div v-for="event in timeline" :key="event.id" class="relative rounded-lg border border-slate-200 bg-slate-50/50 p-3 pl-4 dark:border-slate-700 dark:bg-slate-800/40">
                            <span class="absolute left-1.5 top-4 h-2 w-2 rounded-full bg-slate-500 dark:bg-slate-300"></span>
                            <div class="mb-1 text-xs text-slate-500 dark:text-slate-400">{{ event.happened_at || event.created_at }}</div>
                            <div class="mb-2 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ event.event }}</div>
                            <div class="mb-1 text-xs text-slate-600 dark:text-slate-300">{{ t(messages, 'old') }}: {{ renderValue(event.old_values) }}</div>
                            <div class="text-xs text-slate-600 dark:text-slate-300">{{ t(messages, 'new') }}: {{ renderValue(event.new_values) }}</div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
