<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Draggable from 'vuedraggable';
import RecordTable from '../../Components/RecordTable.vue';
import RecordForm from '../../Components/RecordForm.vue';
import { rtEmpty, rtTdTruncate, rtThDense, rtTheadSticky } from '../../Components/recordTableClasses';
import { useAppPreferences } from '../../composables/useAppPreferences';

const perPage = 50;
let searchDebounceId = null;
const skipSearchRefetch = ref(false);

const inertiaPage = usePage();

const props = defineProps({
    directories: {
        type: Array,
        default: () => [],
    },
});

const menu = ref(props.directories);
const selectedKey = ref(null);
const canManageAccess = computed(
    () => inertiaPage.props.auth?.user?.can?.manageUsers === true || inertiaPage.props.auth?.user?.can?.manageRoles === true,
);
const showAccessInToolbar = computed(() => canManageAccess.value && selectedKey.value === 'users');
const hiddenKeys = ref([]);
const settingsOpen = ref(false);
const hiddenKeysStorageKey = 'colife.directories.hiddenKeys';
const selectedId = ref(null);
const loading = ref(false);
const rowDetailLoading = ref(false);
const cardModalOpen = ref(false);
const cardModalTab = ref('form');
const query = ref('');
const rows = ref([]);
const fields = ref([]);
const form = ref({});
const timeline = ref([]);
const page = ref(1);
const sortField = ref(null);
const sortDir = ref('desc');
const paginationMeta = ref({
    current_page: 1,
    last_page: 1,
    per_page: perPage,
    total: 0,
    from: null,
    to: null,
});
const directoryDetail = ref(null);
const orderedFields = ref([]);
const tableScrollRef = ref(null);
const showTableScrollLeft = ref(false);
const showTableScrollRight = ref(false);
let tableScrollRaf = null;
let tableScrollDir = 0;
let tableScrollResizeObserver = null;
const TABLE_SCROLL_SPEED = 12;
const { locale, theme, initLocale, initTheme, toggleLocale, toggleTheme, t } = useAppPreferences();

const fieldItemKey = (f) => f;

const columnOrderStorageKey = (directoryKey) => {
    const userId = inertiaPage.props.auth?.user?.id ?? '0';

    return `colife.directoryColumns.v1.${userId}.${directoryKey}`;
};

const mergeOrderedFields = (apiFields, directoryKey) => {
    if (!directoryKey || apiFields.length === 0) {
        return [...apiFields];
    }

    let saved = [];

    try {
        const raw = localStorage.getItem(columnOrderStorageKey(directoryKey));
        saved = raw ? JSON.parse(raw) : [];
    } catch {
        saved = [];
    }

    if (!Array.isArray(saved) || saved.length === 0) {
        return [...apiFields];
    }

    const set = new Set(apiFields);
    const out = saved.filter((f) => set.has(f));

    for (const f of apiFields) {
        if (!out.includes(f)) {
            out.push(f);
        }
    }

    return out;
};

const persistColumnOrder = () => {
    const key = selectedKey.value;

    if (!key || orderedFields.value.length === 0) {
        return;
    }

    try {
        localStorage.setItem(columnOrderStorageKey(key), JSON.stringify(orderedFields.value));
    } catch {
        /* quota */
    }
};

const messages = {
    ru: {
        directories: 'Меню',
        profile: 'Профиль',
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
        'bitrix-units-snapshot': 'Снапшот юнитов (Bitrix)',
        access: 'Доступ',
        menuEmpty: 'Нет пунктов меню, доступных по вашим правам',
        page: 'Стр.',
        pageOf: 'из',
        prev: 'Назад',
        next: 'Вперёд',
        totalRows: 'всего',
        dragColumn: 'Перетащить колонку',
        scrollTableLeft: 'Прокрутить влево (удерживайте наведение)',
        scrollTableRight: 'Прокрутить вправо (удерживайте наведение)',
        closeCard: 'Закрыть',
    },
    en: {
        directories: 'Menu',
        profile: 'Profile',
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
        'bitrix-units-snapshot': 'Bitrix units snapshot',
        access: 'Access',
        menuEmpty: 'No menu items are available for your account',
        page: 'Page',
        pageOf: 'of',
        prev: 'Previous',
        next: 'Next',
        totalRows: 'total',
        dragColumn: 'Reorder column',
        scrollTableLeft: 'Scroll left (hold hover)',
        scrollTableRight: 'Scroll right (hold hover)',
        closeCard: 'Close',
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
const rowPrimaryKey = (row) => {
    const idName = directoryDetail.value?.id ?? 'id';
    const key = row[idName];
    return key !== undefined && key !== null ? key : row.id;
};

const escapeHtml = (s) =>
    String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

const escapeRegex = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const highlightHtml = (value, search) => {
    const text = renderValue(value);
    const q = search.trim();
    const escaped = escapeHtml(text);

    if (!q) {
        return escaped;
    }

    try {
        return escaped.replace(
            new RegExp(`(${escapeRegex(q)})`, 'gi'),
            '<mark class="rounded bg-amber-200 px-0.5 dark:bg-amber-500/35">$1</mark>',
        );
    } catch {
        return escaped;
    }
};

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

const fetchRows = async () => {
    if (!current.value) {
        return;
    }

    loading.value = true;

    try {
        const params = {
            page: page.value,
            per_page: perPage,
            direction: sortDir.value,
        };

        if (sortField.value) {
            params.sort = sortField.value;
        }

        if (query.value.trim()) {
            params.search = query.value.trim();
        }

        const { data } = await window.axios.get(`/api/directories/${current.value.key}`, { params });
        rows.value = data.rows ?? [];
        fields.value = data.fields ?? [];
        directoryDetail.value = data.directory ?? null;
        paginationMeta.value = {
            ...paginationMeta.value,
            ...(data.meta ?? {}),
        };
    } finally {
        loading.value = false;
    }
};

const closeCardModal = () => {
    cardModalOpen.value = false;
    rowDetailLoading.value = false;
    cardModalTab.value = 'form';
    selectedId.value = null;
    form.value = {};
    timeline.value = [];
};

const loadList = async () => {
    cardModalOpen.value = false;
    rowDetailLoading.value = false;
    cardModalTab.value = 'form';

    if (!current.value) {
        selectedId.value = null;
        query.value = '';
        form.value = {};
        timeline.value = [];
        rows.value = [];
        fields.value = [];
        orderedFields.value = [];
        directoryDetail.value = null;
        paginationMeta.value = {
            current_page: 1,
            last_page: 1,
            per_page: perPage,
            total: 0,
            from: null,
            to: null,
        };

        return;
    }

    selectedId.value = null;
    skipSearchRefetch.value = true;
    query.value = '';
    form.value = {};
    timeline.value = [];
    page.value = 1;
    sortField.value = null;
    sortDir.value = 'desc';
    rows.value = [];
    fields.value = [];
    orderedFields.value = [];

    await fetchRows();
    await nextTick();
    skipSearchRefetch.value = false;
};

const toggleSort = (field) => {
    if (sortField.value === field) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortField.value = field;
        sortDir.value = 'asc';
    }

    page.value = 1;
    fetchRows();
};

const goPage = (p) => {
    const last = paginationMeta.value.last_page ?? 1;
    const next = Math.min(Math.max(1, p), last);
    page.value = next;
    fetchRows();
};

const selectDirectory = (key, toggleContactsExpansion = false) => {
    selectedKey.value = key;
    if (toggleContactsExpansion) {
        contactsExpanded.value = !contactsExpanded.value;
    }
    loadList();
};

const selectRow = async (row) => {
    const rowId = typeof row === 'object' && row !== null ? rowPrimaryKey(row) : row;

    if (!current.value || rowId === undefined || rowId === null || rowId === '') {
        return;
    }

    selectedId.value = rowId;
    cardModalTab.value = 'form';
    cardModalOpen.value = true;
    rowDetailLoading.value = true;
    form.value = {};
    timeline.value = [];

    try {
        const { data } = await window.axios.get(`/api/directories/${current.value.key}/${rowId}`);
        form.value = { ...(data.row ?? {}) };
        timeline.value = data.timeline ?? [];
    } catch {
        closeCardModal();
    } finally {
        rowDetailLoading.value = false;
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

const updateTableScrollHints = () => {
    const el = tableScrollRef.value;

    if (!el) {
        showTableScrollLeft.value = false;
        showTableScrollRight.value = false;

        return;
    }

    const { scrollLeft, scrollWidth, clientWidth } = el;
    const overflow = scrollWidth - clientWidth;

    if (overflow <= 4) {
        showTableScrollLeft.value = false;
        showTableScrollRight.value = false;

        return;
    }

    showTableScrollLeft.value = scrollLeft > 4;
    showTableScrollRight.value = scrollLeft < overflow - 4;
};

const stopAutoTableScroll = () => {
    tableScrollDir = 0;

    if (tableScrollRaf !== null) {
        cancelAnimationFrame(tableScrollRaf);
        tableScrollRaf = null;
    }
};

const tickTableScroll = () => {
    const el = tableScrollRef.value;

    if (!el || tableScrollDir === 0) {
        tableScrollRaf = null;

        return;
    }

    const max = el.scrollWidth - el.clientWidth;

    if (max <= 0) {
        stopAutoTableScroll();
        updateTableScrollHints();

        return;
    }

    if (tableScrollDir > 0) {
        el.scrollLeft = Math.min(el.scrollLeft + TABLE_SCROLL_SPEED, max);

        if (el.scrollLeft >= max - 1) {
            stopAutoTableScroll();
        }
    } else {
        el.scrollLeft = Math.max(el.scrollLeft - TABLE_SCROLL_SPEED, 0);

        if (el.scrollLeft <= 1) {
            stopAutoTableScroll();
        }
    }

    updateTableScrollHints();
    tableScrollRaf = requestAnimationFrame(tickTableScroll);
};

const startAutoTableScroll = (dir) => {
    stopAutoTableScroll();
    tableScrollDir = dir;
    tableScrollRaf = requestAnimationFrame(tickTableScroll);
};

const nudgeTableScroll = (dir) => {
    const el = tableScrollRef.value;

    if (!el) {
        return;
    }

    const max = el.scrollWidth - el.clientWidth;
    const step = Math.max(240, Math.floor(el.clientWidth * 0.45));

    if (dir > 0) {
        el.scrollLeft = Math.min(el.scrollLeft + step, max);
    } else {
        el.scrollLeft = Math.max(el.scrollLeft - step, 0);
    }

    updateTableScrollHints();
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
    nextTick(() => {
        const el = tableScrollRef.value;

        if (el && typeof ResizeObserver !== 'undefined') {
            tableScrollResizeObserver = new ResizeObserver(() => updateTableScrollHints());
            tableScrollResizeObserver.observe(el);
        }

        updateTableScrollHints();
    });
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

watch(query, () => {
    if (skipSearchRefetch.value || !current.value) {
        return;
    }

    clearTimeout(searchDebounceId);

    searchDebounceId = setTimeout(() => {
        page.value = 1;
        fetchRows();
    }, 320);
});

watch(
    () => [fields.value, selectedKey.value],
    () => {
        const dirKey = selectedKey.value;
        const f = fields.value;

        if (!dirKey || f.length === 0) {
            orderedFields.value = [];

            return;
        }

        orderedFields.value = mergeOrderedFields([...f], dirKey);
    },
);

watch([rows, orderedFields, selectedKey], () => {
    nextTick(() => updateTableScrollHints());
});

onBeforeUnmount(() => {
    stopAutoTableScroll();

    if (tableScrollResizeObserver) {
        tableScrollResizeObserver.disconnect();
        tableScrollResizeObserver = null;
    }
});
</script>

<template>
    <div class="min-h-screen bg-slate-100 p-4 md:p-6 dark:bg-slate-950">
        <div class="mx-auto max-w-[1700px] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <div class="grid min-h-[calc(100vh-3rem)] grid-cols-1 lg:grid-cols-[260px_minmax(0,1fr)]">
                <aside class="flex min-h-0 flex-col border-b border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900 lg:h-full lg:max-h-[calc(100vh-3rem)] lg:border-r lg:border-b-0">
                    <div class="mb-4 flex shrink-0 items-center justify-between gap-3">
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

                    <div class="min-h-0 flex-1 overflow-y-auto">
                    <p v-if="menu.length === 0" class="text-sm text-slate-500 dark:text-slate-400">
                        {{ t(messages, 'menuEmpty') }}
                    </p>
                    <div v-else class="space-y-1.5">
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
                    </div>
                    <div
                        v-if="inertiaPage.props.auth?.user"
                        class="mt-4 shrink-0 border-t border-slate-200 pt-4 dark:border-slate-700"
                    >
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t(messages, 'profile') }}</p>
                        <p class="mt-1 truncate text-sm font-medium text-slate-900 dark:text-slate-100">{{ inertiaPage.props.auth.user.name }}</p>
                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ inertiaPage.props.auth.user.email }}</p>
                        <div class="mt-3 flex w-full flex-col gap-2">
                            <button
                                type="button"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 transition hover:bg-white dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                                @click="toggleLocale"
                            >
                                {{ t(messages, 'lang') }}: {{ locale.toUpperCase() }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 transition hover:bg-white dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
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
                                    class="h-4 w-4 shrink-0 text-yellow-500 dark:text-yellow-400"
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
                                    class="h-4 w-4 shrink-0"
                                    aria-hidden="true"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M21 12.79A9 9 0 1 1 11.21 3a7 7 0 0 0 9.79 9.79Z"
                                    />
                                </svg>
                                <span>{{ theme === 'dark' ? t(messages, 'light') : t(messages, 'dark') }}</span>
                            </button>
                            <button
                                type="button"
                                class="w-full rounded-lg border border-red-600 bg-red-600 px-3 py-2 text-xs font-medium text-white transition hover:bg-red-700 dark:border-red-500 dark:bg-red-500 dark:hover:bg-red-600"
                                @click="logout"
                            >
                                {{ t(messages, 'logout') }}
                            </button>
                        </div>
                    </div>
                </aside>

                <section class="flex min-h-0 min-w-0 flex-col overflow-hidden border-b border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900 lg:h-full lg:max-h-[calc(100vh-3rem)] lg:border-r lg:border-b-0">
                    <div v-if="menu.length === 0" class="rounded-lg border border-dashed border-slate-300 bg-slate-50/80 p-8 text-center text-sm text-slate-600 dark:border-slate-600 dark:bg-slate-800/40 dark:text-slate-300">
                        {{ t(messages, 'menuEmpty') }}
                    </div>
                    <template v-else>
                    <div class="shrink-0 space-y-3 pb-3">
                        <div class="flex min-w-0 flex-nowrap items-center gap-2 overflow-x-auto border-b border-slate-100 pb-3 text-slate-600 dark:border-slate-800 dark:text-slate-300">
                            <span
                                v-if="paginationMeta.from != null && paginationMeta.to != null"
                                class="shrink-0 whitespace-nowrap text-xs text-slate-500 dark:text-slate-400"
                            >
                                {{ paginationMeta.from }}–{{ paginationMeta.to }}
                            </span>
                            <template v-if="rows.length > 0 || (paginationMeta.last_page ?? 1) > 1">
                                <button
                                    type="button"
                                    class="shrink-0 rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium disabled:opacity-40 dark:border-slate-600"
                                    :disabled="(paginationMeta.current_page ?? 1) <= 1 || loading"
                                    @click="goPage((paginationMeta.current_page ?? 1) - 1)"
                                >
                                    {{ t(messages, 'prev') }}
                                </button>
                                <span class="shrink-0 whitespace-nowrap text-xs">{{ paginationMeta.current_page ?? 1 }} / {{ paginationMeta.last_page ?? 1 }}</span>
                                <button
                                    type="button"
                                    class="shrink-0 rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium disabled:opacity-40 dark:border-slate-600"
                                    :disabled="(paginationMeta.current_page ?? 1) >= (paginationMeta.last_page ?? 1) || loading"
                                    @click="goPage((paginationMeta.current_page ?? 1) + 1)"
                                >
                                    {{ t(messages, 'next') }}
                                </button>
                            </template>
                            <h1 class="min-w-[8rem] flex-1 truncate text-base font-semibold text-slate-900 dark:text-slate-100 sm:min-w-0 sm:text-lg">
                                {{ current?.title ?? 'Directory' }}
                            </h1>
                            <span class="shrink-0 whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                                {{ paginationMeta.total ?? 0 }} {{ t(messages, 'totalRows') }} · {{ t(messages, 'page') }}
                                {{ paginationMeta.current_page ?? 1 }} {{ t(messages, 'pageOf') }} {{ paginationMeta.last_page ?? 1 }}
                            </span>
                            <div class="ms-auto flex shrink-0 items-center gap-2">
                                <span v-if="loading" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t(messages, 'loading') }}</span>
                                <Link
                                    v-if="showAccessInToolbar"
                                    href="/admin/access"
                                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                                >
                                    {{ t(messages, 'access') }}
                                </Link>
                            </div>
                        </div>
                        <div>
                            <input
                                v-model="query"
                                type="text"
                                :placeholder="t(messages, 'search')"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none ring-blue-500 focus:ring-2 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                            >
                        </div>
                    </div>

                    <div class="relative min-h-0 min-w-0 flex-1 overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
                        <button
                            v-show="showTableScrollLeft"
                            type="button"
                            class="absolute left-0 top-0 z-20 flex h-full w-9 shrink-0 items-center justify-center border-0 bg-gradient-to-r from-white via-white/90 to-transparent py-2 pl-1 pr-2 text-slate-600 shadow-none transition hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:from-slate-900 dark:via-slate-900/90 dark:text-slate-300 dark:hover:text-white"
                            :aria-label="t(messages, 'scrollTableLeft')"
                            @mouseenter="startAutoTableScroll(-1)"
                            @mouseleave="stopAutoTableScroll"
                            @click="nudgeTableScroll(-1)"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-6 w-6" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 6l-6 6 6 6" />
                            </svg>
                        </button>
                        <button
                            v-show="showTableScrollRight"
                            type="button"
                            class="absolute right-0 top-0 z-20 flex h-full w-9 shrink-0 items-center justify-center border-0 bg-gradient-to-l from-white via-white/90 to-transparent py-2 pl-2 pr-1 text-slate-600 shadow-none transition hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:from-slate-900 dark:via-slate-900/90 dark:text-slate-300 dark:hover:text-white"
                            :aria-label="t(messages, 'scrollTableRight')"
                            @mouseenter="startAutoTableScroll(1)"
                            @mouseleave="stopAutoTableScroll"
                            @click="nudgeTableScroll(1)"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-6 w-6" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" />
                            </svg>
                        </button>
                        <div
                            ref="tableScrollRef"
                            class="h-full min-h-0 overflow-auto overscroll-x-contain"
                            @scroll.passive="updateTableScrollHints"
                        >
                        <RecordTable variant="scroll">
                            <thead :class="rtTheadSticky">
                                <Draggable
                                    v-model="orderedFields"
                                    tag="tr"
                                    :item-key="fieldItemKey"
                                    handle=".col-drag-handle"
                                    :animation="200"
                                    ghost-class="!bg-amber-100 dark:!bg-amber-900/30"
                                    class="bg-slate-50 dark:bg-slate-800"
                                    @end="persistColumnOrder"
                                >
                                    <template #item="{ element: field }">
                                        <th :class="rtThDense">
                                            <div class="flex min-w-0 items-center gap-0.5">
                                                <button
                                                    type="button"
                                                    class="col-drag-handle shrink-0 cursor-grab touch-manipulation rounded p-0.5 text-slate-400 hover:bg-slate-200 hover:text-slate-700 active:cursor-grabbing dark:hover:bg-slate-600 dark:hover:text-slate-200"
                                                    :title="t(messages, 'dragColumn')"
                                                    :aria-label="t(messages, 'dragColumn')"
                                                    @click.stop
                                                >
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4 shrink-0" aria-hidden="true">
                                                        <path d="M8 6a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm0 6a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm-2 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm8-14a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm-2 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm6-12a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm-2 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z" />
                                                    </svg>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="min-w-0 flex-1 cursor-pointer px-1 text-left transition hover:text-slate-800 dark:hover:text-slate-100"
                                                    @click="toggleSort(field)"
                                                >
                                                    <span class="inline-flex min-w-0 items-center gap-1">
                                                        <span class="truncate">{{ field }}</span>
                                                        <span v-if="sortField === field" class="shrink-0 text-[10px] font-bold text-slate-800 dark:text-slate-100" aria-hidden="true">
                                                            {{ sortDir === 'asc' ? '↑' : '↓' }}
                                                        </span>
                                                    </span>
                                                </button>
                                            </div>
                                        </th>
                                    </template>
                                </Draggable>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in rows"
                                    :key="String(rowPrimaryKey(row))"
                                    class="cursor-pointer transition hover:bg-slate-50 dark:hover:bg-slate-800/50"
                                    :class="String(selectedId) === String(rowPrimaryKey(row)) ? 'bg-blue-50/70 dark:bg-blue-900/30' : ''"
                                    @click="selectRow(row)"
                                >
                                    <td
                                        v-for="field in orderedFields"
                                        :key="`${rowPrimaryKey(row)}-${field}`"
                                        :class="rtTdTruncate"
                                        v-html="highlightHtml(row[field], query)"
                                    ></td>
                                </tr>
                                <tr v-if="rows.length === 0 && !loading">
                                    <td :colspan="Math.max(orderedFields.length, 1)" :class="rtEmpty">
                                        {{ t(messages, 'empty') }}
                                    </td>
                                </tr>
                            </tbody>
                        </RecordTable>
                        </div>
                    </div>

                    </template>
                </section>
            </div>
        </div>

        <Transition
            enter-active-class="transition-opacity duration-300 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-200 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="cardModalOpen && menu.length > 0"
                class="fixed inset-0 z-[60] bg-black/40"
                aria-hidden="true"
                @click="closeCardModal"
            ></div>
        </Transition>
        <Transition
            enter-active-class="transition-transform duration-300 ease-out"
            enter-from-class="translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transition-transform duration-300 ease-in"
            leave-from-class="translate-x-0"
            leave-to-class="translate-x-full"
        >
            <aside
                v-if="cardModalOpen && menu.length > 0"
                class="fixed right-0 top-0 z-[70] flex h-full w-[min(80vw,87.5rem)] min-w-[18rem] flex-col border-l border-slate-200 bg-white shadow-[-8px_0_32px_rgba(0,0,0,0.12)] dark:border-slate-700 dark:bg-slate-900 dark:shadow-[-8px_0_32px_rgba(0,0,0,0.4)]"
                role="dialog"
                aria-modal="true"
                :aria-label="t(messages, 'card')"
            >
                    <div class="flex shrink-0 items-center justify-between gap-4 border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                        <h2 class="min-w-0 truncate text-lg font-semibold text-slate-900 dark:text-slate-100 md:text-xl">
                            {{ current?.title ?? 'Directory' }}
                        </h2>
                        <button
                            type="button"
                            class="shrink-0 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                            @click="closeCardModal"
                        >
                            {{ t(messages, 'closeCard') }}
                        </button>
                    </div>
                    <div class="flex shrink-0 gap-1 border-b border-slate-200 px-4 dark:border-slate-700">
                        <button
                            type="button"
                            class="border-b-2 px-5 py-3.5 text-base font-medium transition"
                            :class="
                                cardModalTab === 'form'
                                    ? 'border-slate-900 text-slate-900 dark:border-slate-100 dark:text-slate-100'
                                    : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'
                            "
                            @click="cardModalTab = 'form'"
                        >
                            {{ t(messages, 'card') }}
                        </button>
                        <button
                            type="button"
                            class="border-b-2 px-5 py-3.5 text-base font-medium transition"
                            :class="
                                cardModalTab === 'timeline'
                                    ? 'border-slate-900 text-slate-900 dark:border-slate-100 dark:text-slate-100'
                                    : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'
                            "
                            @click="cardModalTab = 'timeline'"
                        >
                            {{ t(messages, 'timeline') }}
                        </button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <div v-if="rowDetailLoading" class="flex items-center justify-center py-24 text-lg text-slate-500 dark:text-slate-400">
                            {{ t(messages, 'loading') }}
                        </div>
                        <template v-else>
                            <RecordForm
                                v-show="cardModalTab === 'form'"
                                :record="form"
                                :empty-message="t(messages, 'select')"
                            />
                            <div v-show="cardModalTab === 'timeline'" class="p-6 md:p-8 lg:p-10">
                                <div v-if="timeline.length === 0" class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-lg text-slate-500 dark:border-slate-600 dark:text-slate-400">
                                    {{ t(messages, 'noEvents') }}
                                </div>
                                <div v-else class="space-y-5">
                                    <div
                                        v-for="event in timeline"
                                        :key="event.id"
                                        class="relative rounded-xl border border-slate-200 bg-slate-50/80 p-5 pl-6 dark:border-slate-700 dark:bg-slate-800/50"
                                    >
                                        <span class="absolute left-2 top-6 h-2.5 w-2.5 rounded-full bg-slate-500 dark:bg-slate-300"></span>
                                        <div class="mb-2 text-sm text-slate-500 dark:text-slate-400">{{ event.happened_at || event.created_at }}</div>
                                        <div class="mb-3 text-lg font-semibold text-slate-900 dark:text-slate-100">{{ event.event }}</div>
                                        <div class="mb-2 text-base text-slate-600 dark:text-slate-300">{{ t(messages, 'old') }}: {{ renderValue(event.old_values) }}</div>
                                        <div class="text-base text-slate-600 dark:text-slate-300">{{ t(messages, 'new') }}: {{ renderValue(event.new_values) }}</div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
            </aside>
        </Transition>
    </div>
</template>
