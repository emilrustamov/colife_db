<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { MENU_GROUPS, MENU_TONE_CLASSES, orderMenuItems } from '../menuGroups';

const SIDEBAR_SCROLL_KEY = 'colife.sidebar.navScrollTop';
let sidebarScrollTop = 0;

try {
    const raw = sessionStorage.getItem(SIDEBAR_SCROLL_KEY);
    const parsed = raw === null ? NaN : Number(raw);
    if (Number.isFinite(parsed) && parsed >= 0) {
        sidebarScrollTop = parsed;
    }
} catch {
    sidebarScrollTop = 0;
}

const props = defineProps({
    menu: { type: Array, default: () => [] },
    visibleTopLevelMenu: { type: Array, default: () => [] },
    visibleContactsChildren: { type: Array, default: () => [] },
    contactsExpanded: { type: Boolean, default: false },
    isContactsChildSelected: { type: Boolean, default: false },
    selectedKey: { type: String, default: null },
    hiddenKeys: { type: Array, default: () => [] },
    settingsOpen: { type: Boolean, default: false },
    inertiaPage: { type: Object, default: () => ({}) },
    locale: { type: String, default: 'ru' },
    theme: { type: String, default: 'light' },
    t: { type: Function, required: true },
    messages: { type: Object, required: true },
    translateMenuTitle: { type: Function, required: true },
    setSettingsOpen: { type: Function, required: true },
    setItemVisible: { type: Function, required: true },
    selectDirectory: { type: Function, required: true },
    toggleLocale: { type: Function, required: true },
    toggleTheme: { type: Function, required: true },
    logout: { type: Function, required: true },
});

const contactChildrenKeys = ['contact-phones', 'contact-emails'];

/**
 * @param {{ key: string, title?: string }} a
 * @param {{ key: string, title?: string }} b
 * @returns {number}
 */
const byLabel = (a, b) => {
    const left = props.translateMenuTitle(a.key, a.title);
    const right = props.translateMenuTitle(b.key, b.title);

    return left.localeCompare(right, props.locale === 'en' ? 'en' : 'ru', { sensitivity: 'base' });
};

const toneByKey = computed(() => {
    const map = {};

    for (const group of MENU_GROUPS) {
        for (const key of group.keys) {
            map[key] = group.tone;
        }
    }

    return map;
});

/**
 * @param {string} key
 * @returns {{ icon: string, active: string, dot: string }}
 */
const toneClasses = (key) => MENU_TONE_CLASSES[toneByKey.value[key]] ?? MENU_TONE_CLASSES.cyan;

const groupedNav = computed(() => {
    const topLevel = props.visibleTopLevelMenu;
    const byKey = new Map(topLevel.map((item) => [item.key, item]));
    const used = new Set();
    const groups = [];

    for (const group of MENU_GROUPS) {
        const items = [];

        for (const key of group.keys) {
            if (contactChildrenKeys.includes(key)) {
                continue;
            }

            const item = byKey.get(key);
            if (item) {
                items.push(item);
                used.add(key);
            }
        }

        if (items.length > 0) {
            groups.push({
                id: group.id,
                labelKey: group.labelKey,
                tone: group.tone,
                items,
            });
        }
    }

    const leftovers = topLevel.filter((item) => !used.has(item.key));
    if (leftovers.length > 0) {
        groups.push({
            id: 'other',
            labelKey: 'directories',
            tone: 'cyan',
            items: leftovers,
        });
    }

    return groups;
});

const groupedSettings = computed(() => {
    const byKey = new Map(props.menu.map((item) => [item.key, item]));
    const used = new Set();
    const groups = [];

    for (const group of MENU_GROUPS) {
        const items = [];

        for (const key of group.keys) {
            const item = byKey.get(key);
            if (item) {
                items.push(item);
                used.add(key);
            }
        }

        if (items.length > 0) {
            groups.push({
                id: group.id,
                labelKey: group.labelKey,
                items,
            });
        }
    }

    const leftovers = props.menu.filter((item) => !used.has(item.key)).sort(byLabel);
    if (leftovers.length > 0) {
        groups.push({
            id: 'other',
            labelKey: 'directories',
            items: leftovers,
        });
    }

    return groups;
});

const sortedContactsChildren = computed(() =>
    orderMenuItems([...props.visibleContactsChildren], contactChildrenKeys),
);

/**
 * @param {string} key
 * @returns {boolean}
 */
const isActive = (key) => props.selectedKey === key;

/**
 * @param {{ key: string }} item
 * @returns {boolean}
 */
const isContactsBranchActive = (item) =>
    item.key === 'contacts' && (isActive('contacts') || props.isContactsChildSelected);

const navRef = ref(null);

/**
 * @returns {void}
 */
const persistNavScroll = () => {
    const el = navRef.value;
    if (!el) {
        return;
    }

    sidebarScrollTop = el.scrollTop;

    try {
        sessionStorage.setItem(SIDEBAR_SCROLL_KEY, String(sidebarScrollTop));
    } catch {}
};

/**
 * @returns {void}
 */
const restoreNavScroll = () => {
    const el = navRef.value;
    if (!el) {
        return;
    }

    el.scrollTop = sidebarScrollTop;
};

onMounted(() => {
    nextTick(() => {
        restoreNavScroll();
        requestAnimationFrame(restoreNavScroll);
    });
});

onBeforeUnmount(() => {
    persistNavScroll();
});

watch(
    () => [props.selectedKey, props.settingsOpen, props.menu.length, groupedNav.value.length],
    () => {
        nextTick(() => {
            restoreNavScroll();
            requestAnimationFrame(restoreNavScroll);
        });
    },
);
</script>

<template>
    <aside class="ui-sidebar flex h-full min-h-0 flex-col overflow-hidden border-b border-line lg:border-r lg:border-b-0">
        <div class="flex shrink-0 items-center justify-between gap-2 px-3.5 py-3.5">
            <img
                src="/images/colife-logo.png"
                alt="COLIFE"
                class="ui-logo"
            >
            <button
                type="button"
                class="ui-btn-icon"
                :class="settingsOpen ? 'ui-btn-icon-active' : ''"
                :aria-label="t(messages, 'menuSettings')"
                :aria-pressed="settingsOpen"
                @click="setSettingsOpen(!settingsOpen)"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4 transition duration-300" :class="settingsOpen ? 'rotate-45' : ''" aria-hidden="true">
                    <path d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.07 7.07 0 0 0-1.63-.94l-.36-2.54A.5.5 0 0 0 13.9 2h-3.8a.5.5 0 0 0-.49.42l-.36 2.54c-.59.24-1.13.55-1.63.94l-2.39-.96a.5.5 0 0 0-.6.22L2.71 8.48a.5.5 0 0 0 .12.64l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94l-2.03 1.58a.5.5 0 0 0-.12.64l1.92 3.32a.5.5 0 0 0 .6.22l2.39-.96c.5.39 1.04.7 1.63.94l.36 2.54a.5.5 0 0 0 .49.42h3.8a.5.5 0 0 0 .49-.42l.36-2.54c.59-.24 1.13-.55 1.63-.94l2.39.96a.5.5 0 0 0 .6-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58ZM12 15.5A3.5 3.5 0 1 1 12 8.5a3.5 3.5 0 0 1 0 7Z" />
                </svg>
            </button>
        </div>

        <Transition
            enter-active-class="transition duration-250 ease-out"
            enter-from-class="opacity-0 -translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-1"
        >
            <div
                v-if="settingsOpen"
                class="ui-panel mx-3 mb-3 p-3"
            >
                <p class="ui-nav-group-label mb-2">
                    {{ t(messages, 'menuSettings') }}
                </p>
                <div class="ui-scroll max-h-56 space-y-3 overflow-auto pr-1">
                    <div v-for="group in groupedSettings" :key="group.id">
                        <p class="mb-1 px-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-ink-muted">
                            {{ t(messages, group.labelKey) }}
                        </p>
                        <div class="space-y-0.5">
                            <label
                                v-for="item in group.items"
                                :key="item.key"
                                class="flex cursor-pointer items-center gap-2 rounded-lg px-1.5 py-1 text-sm text-ink transition hover:bg-black/[0.04] dark:hover:bg-white/[0.05]"
                            >
                                <input
                                    type="checkbox"
                                    class="ui-check"
                                    :checked="!hiddenKeys.includes(item.key)"
                                    @change="setItemVisible(item.key, $event.target.checked)"
                                >
                                <span
                                    class="ui-nav-icon !h-6 !w-6 text-[12px]"
                                    :class="toneClasses(item.key).icon"
                                >{{ item.icon || '•' }}</span>
                                <span class="truncate">{{ translateMenuTitle(item.key, item.title) }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>

        <nav
            ref="navRef"
            class="ui-scroll min-h-0 flex-1 overflow-y-auto px-2.5 pb-3"
            @scroll.passive="persistNavScroll"
        >
            <p v-if="menu.length === 0" class="ui-muted px-2">
                {{ t(messages, 'menuEmpty') }}
            </p>
            <div v-else class="space-y-4">
                <section v-for="group in groupedNav" :key="group.id">
                    <p class="ui-nav-group-label">
                        {{ t(messages, group.labelKey) }}
                    </p>
                    <div class="space-y-0.5">
                        <div v-for="item in group.items" :key="item.key">
                            <button
                                type="button"
                                class="ui-nav-item"
                                :class="(isActive(item.key) || isContactsBranchActive(item)) ? ['ui-nav-item-active', toneClasses(item.key).active] : ''"
                                @click="selectDirectory(item.key, item.key === 'contacts')"
                            >
                                <span
                                    class="ui-nav-icon"
                                    :class="toneClasses(item.key).icon"
                                >{{ item.icon || '•' }}</span>
                                <span class="min-w-0 flex-1 truncate">{{ translateMenuTitle(item.key, item.title) }}</span>
                                <span
                                    v-if="item.key === 'contacts' && sortedContactsChildren.length > 0"
                                    class="text-[10px] font-semibold text-ink-muted"
                                    aria-hidden="true"
                                >{{ contactsExpanded || isContactsChildSelected ? '▾' : '▸' }}</span>
                            </button>

                            <div
                                v-if="item.key === 'contacts' && (contactsExpanded || isContactsChildSelected) && sortedContactsChildren.length > 0"
                                class="relative ml-5 mt-0.5 space-y-0.5 border-l border-line pl-2.5"
                            >
                                <button
                                    v-for="child in sortedContactsChildren"
                                    :key="child.key"
                                    type="button"
                                    class="ui-nav-item !py-1.5"
                                    :class="isActive(child.key) ? ['ui-nav-item-active', toneClasses(child.key).active] : ''"
                                    @click="selectDirectory(child.key)"
                                >
                                    <span
                                        class="ui-nav-icon !h-6 !w-6 text-[12px]"
                                        :class="toneClasses(child.key).icon"
                                    >{{ child.icon || '•' }}</span>
                                    <span class="min-w-0 flex-1 truncate">{{ translateMenuTitle(child.key, child.title) }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </nav>

        <div
            v-if="inertiaPage.props.auth?.user"
            class="shrink-0 border-t border-line bg-surface-muted/80 p-3"
        >
            <div class="mb-2.5 min-w-0">
                <p class="truncate text-sm font-semibold text-ink">{{ inertiaPage.props.auth.user.name }}</p>
                <p class="truncate text-xs text-ink-muted">{{ inertiaPage.props.auth.user.email }}</p>
            </div>
            <div class="grid grid-cols-2 gap-1.5">
                <button
                    type="button"
                    class="ui-btn ui-btn-secondary"
                    @click="toggleLocale"
                >
                    {{ locale.toUpperCase() }}
                </button>
                <button
                    type="button"
                    class="ui-btn ui-btn-secondary"
                    @click="toggleTheme"
                    :aria-label="theme === 'dark' ? t(messages, 'light') : t(messages, 'dark')"
                >
                    <svg
                        v-if="theme === 'dark'"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.75"
                        class="h-3.5 w-3.5 shrink-0 text-amber-400"
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
                        stroke-width="1.75"
                        class="h-3.5 w-3.5 shrink-0"
                        aria-hidden="true"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M21 12.79A9 9 0 1 1 11.21 3a7 7 0 0 0 9.79 9.79Z"
                        />
                    </svg>
                </button>
            </div>
            <button
                type="button"
                class="ui-btn ui-btn-danger mt-1.5 w-full"
                @click="logout"
            >
                {{ t(messages, 'logout') }}
            </button>
        </div>
    </aside>
</template>
