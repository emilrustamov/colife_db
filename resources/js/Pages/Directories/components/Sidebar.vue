<script setup>
defineProps({
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
</script>

<template>
    <aside class="flex min-h-0 flex-col border-b border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900 lg:h-full lg:max-h-[calc(100vh-3rem)] lg:border-r lg:border-b-0">
        <div class="mb-4 flex shrink-0 items-center justify-between gap-3">
            <h2 class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500 dark:text-slate-400">{{ t(messages, 'directories') }}</h2>
            <button
                type="button"
                class="inline-flex items-center justify-center rounded-lg border border-slate-300 p-2 text-slate-700 transition hover:bg-slate-100 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                :aria-label="t(messages, 'menuSettings')"
                @click="setSettingsOpen(!settingsOpen)"
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
</template>
