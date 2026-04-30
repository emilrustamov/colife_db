<script setup>
import Draggable from 'vuedraggable';
import RecordTable from '../../../Components/RecordTable.vue';
import { rtEmpty, rtTdTruncate, rtThDense, rtTheadSticky } from '../../../Components/recordTableClasses';

defineProps({
    menu: { type: Array, default: () => [] },
    paginationMeta: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    current: { type: Object, default: null },
    messages: { type: Object, required: true },
    t: { type: Function, required: true },
    isUsersDirectory: { type: Boolean, default: false },
    isRolesDirectory: { type: Boolean, default: false },
    canManageUsers: { type: Boolean, default: false },
    canManageRoles: { type: Boolean, default: false },
    query: { type: String, default: '' },
    showTableScrollLeft: { type: Boolean, default: false },
    showTableScrollRight: { type: Boolean, default: false },
    orderedFields: { type: Array, default: () => [] },
    fieldItemKey: { type: Function, required: true },
    sortField: { type: String, default: null },
    sortDir: { type: String, default: 'desc' },
    selectedId: { type: [String, Number, null], default: null },
    rowPrimaryKey: { type: Function, required: true },
    highlightHtml: { type: Function, required: true },
    goPage: { type: Function, required: true },
    goRoles: { type: Function, required: true },
    goUsers: { type: Function, required: true },
    openCreateUserModal: { type: Function, required: true },
    openCreateRoleModal: { type: Function, required: true },
    setQuery: { type: Function, required: true },
    startAutoTableScroll: { type: Function, required: true },
    stopAutoTableScroll: { type: Function, required: true },
    nudgeTableScroll: { type: Function, required: true },
    updateTableScrollHints: { type: Function, required: true },
    setTableScrollRef: { type: Function, required: true },
    setOrderedFields: { type: Function, required: true },
    persistColumnOrder: { type: Function, required: true },
    toggleSort: { type: Function, required: true },
    markRowSelected: { type: Function, required: true },
    handleRowDblClick: { type: Function, required: true },
});
</script>

<template>
    <section
        class="flex min-h-0 min-w-0 flex-col overflow-hidden border-b border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900 lg:h-full lg:max-h-[calc(100vh-3rem)] lg:border-r lg:border-b-0">
        <div v-if="menu.length === 0"
            class="rounded-lg border border-dashed border-slate-300 bg-slate-50/80 p-8 text-center text-sm text-slate-600 dark:border-slate-600 dark:bg-slate-800/40 dark:text-slate-300">
            {{ t(messages, 'menuEmpty') }}
        </div>
        <template v-else>
            <div class="shrink-0 space-y-3 pb-3">
                <div
                    class="flex min-w-0 flex-nowrap items-center gap-2 overflow-x-auto border-b border-slate-100 pb-3 text-slate-600 dark:border-slate-800 dark:text-slate-300">
                    <span v-if="paginationMeta.from != null && paginationMeta.to != null"
                        class="shrink-0 whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                        {{ paginationMeta.from }}–{{ paginationMeta.to }}
                    </span>
                    <template v-if="rows.length > 0 || (paginationMeta.last_page ?? 1) > 1">
                        <button type="button"
                            class="shrink-0 rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium disabled:opacity-40 dark:border-slate-600"
                            :disabled="(paginationMeta.current_page ?? 1) <= 1 || loading"
                            @click="goPage((paginationMeta.current_page ?? 1) - 1)">
                            {{ t(messages, 'prev') }}
                        </button>
                        <span class="shrink-0 whitespace-nowrap text-xs">{{ paginationMeta.current_page ?? 1 }} / {{
                            paginationMeta.last_page ?? 1 }}</span>
                        <button type="button"
                            class="shrink-0 rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium disabled:opacity-40 dark:border-slate-600"
                            :disabled="(paginationMeta.current_page ?? 1) >= (paginationMeta.last_page ?? 1) || loading"
                            @click="goPage((paginationMeta.current_page ?? 1) + 1)">
                            {{ t(messages, 'next') }}
                        </button>
                    </template>
                    <h1
                        class="min-w-[8rem] flex-1 truncate text-base font-semibold text-slate-900 dark:text-slate-100 sm:min-w-0 sm:text-lg">
                        {{ current?.title ?? 'Directory' }}
                    </h1>
                    <span class="shrink-0 whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                        {{ paginationMeta.total ?? 0 }} {{ t(messages, 'totalRows') }} · {{ t(messages, 'page') }}
                        {{ paginationMeta.current_page ?? 1 }} {{ t(messages, 'pageOf') }} {{ paginationMeta.last_page
                        ?? 1 }}
                    </span>
                    <div class="ms-auto flex shrink-0 items-center gap-2">
                        <span v-if="loading"
                            class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{
                                t(messages, 'loading') }}</span>
                        <button v-if="isUsersDirectory && canManageRoles" type="button"
                            class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                            @click="goRoles">
                            {{ t(messages, 'goRoles') }}
                        </button>
                        <button v-if="isRolesDirectory && canManageUsers" type="button"
                            class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                            @click="goUsers">
                            {{ t(messages, 'goUsers') }}
                        </button>
                        <button v-if="isUsersDirectory && canManageUsers" type="button"
                            class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
                            @click="openCreateUserModal">
                            {{ t(messages, 'addUser') }}
                        </button>
                        <button v-if="isRolesDirectory && canManageRoles" type="button"
                            class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
                            @click="openCreateRoleModal">
                            {{ t(messages, 'addRole') }}
                        </button>
                    </div>
                </div>
                <div>
                    <input :value="query" type="text" :placeholder="t(messages, 'search')"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none ring-blue-500 focus:ring-2 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                        @input="setQuery($event.target.value)">
                </div>
            </div>

            <div
                class="relative min-h-0 min-w-0 flex-1 overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
                <button v-show="showTableScrollLeft" type="button"
                    class="absolute left-0 top-0 z-20 flex h-full w-9 shrink-0 items-center justify-center border-0 bg-gradient-to-r from-white via-white/90 to-transparent py-2 pl-1 pr-2 text-slate-600 shadow-none transition hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:from-slate-900 dark:via-slate-900/90 dark:text-slate-300 dark:hover:text-white"
                    :aria-label="t(messages, 'scrollTableLeft')" @mouseenter="startAutoTableScroll(-1)"
                    @mouseleave="stopAutoTableScroll" @click="nudgeTableScroll(-1)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" class="h-6 w-6" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 6l-6 6 6 6" />
                    </svg>
                </button>
                <button v-show="showTableScrollRight" type="button"
                    class="absolute right-0 top-0 z-20 flex h-full w-9 shrink-0 items-center justify-center border-0 bg-gradient-to-l from-white via-white/90 to-transparent py-2 pl-2 pr-1 text-slate-600 shadow-none transition hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:from-slate-900 dark:via-slate-900/90 dark:text-slate-300 dark:hover:text-white"
                    :aria-label="t(messages, 'scrollTableRight')" @mouseenter="startAutoTableScroll(1)"
                    @mouseleave="stopAutoTableScroll" @click="nudgeTableScroll(1)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" class="h-6 w-6" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" />
                    </svg>
                </button>
                <div :ref="setTableScrollRef" class="h-full min-h-0 overflow-auto overscroll-x-contain"
                    @scroll.passive="updateTableScrollHints">
                    <RecordTable variant="scroll">
                        <thead :class="rtTheadSticky">
                            <Draggable :model-value="orderedFields" tag="tr" :item-key="fieldItemKey"
                                handle=".col-drag-handle" :animation="200"
                                ghost-class="!bg-amber-100 dark:!bg-amber-900/30" class="bg-slate-50 dark:bg-slate-800"
                                @update:model-value="setOrderedFields" @end="persistColumnOrder">
                                <template #item="{ element: field }">
                                    <th :class="rtThDense">
                                        <div class="flex min-w-0 items-center gap-0.5">
                                            <button type="button"
                                                class="col-drag-handle shrink-0 cursor-grab touch-manipulation rounded p-0.5 text-slate-400 hover:bg-slate-200 hover:text-slate-700 active:cursor-grabbing dark:hover:bg-slate-600 dark:hover:text-slate-200"
                                                :title="t(messages, 'dragColumn')"
                                                :aria-label="t(messages, 'dragColumn')" @click.stop>
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                                    fill="currentColor" class="h-4 w-4 shrink-0" aria-hidden="true">
                                                    <path
                                                        d="M8 6a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm0 6a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm-2 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm8-14a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm-2 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm6-12a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm-2 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z" />
                                                </svg>
                                            </button>
                                            <button type="button"
                                                class="min-w-0 flex-1 cursor-pointer px-1 text-left transition hover:text-slate-800 dark:hover:text-slate-100"
                                                @click="toggleSort(field)">
                                                <span class="inline-flex min-w-0 items-center gap-1">
                                                    <span class="truncate">{{ field }}</span>
                                                    <span v-if="sortField === field"
                                                        class="shrink-0 text-[10px] font-bold text-slate-800 dark:text-slate-100"
                                                        aria-hidden="true">
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
                            <tr v-for="row in rows" :key="String(rowPrimaryKey(row))"
                                class="cursor-pointer transition hover:bg-slate-50 dark:hover:bg-slate-800/50"
                                :class="String(selectedId) === String(rowPrimaryKey(row)) ? 'bg-blue-50/70 dark:bg-blue-900/30' : ''"
                                @click="markRowSelected(row)" @dblclick="handleRowDblClick(row)">
                                <td v-for="field in orderedFields" :key="`${rowPrimaryKey(row)}-${field}`"
                                    :class="rtTdTruncate" v-html="highlightHtml(row[field], query)"></td>
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
</template>
