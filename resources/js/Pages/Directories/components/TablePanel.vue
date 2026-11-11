<script setup>
import Draggable from 'vuedraggable';
import RecordTable from '../../../Components/RecordTable.vue';
import { rtEmpty, rtTdTruncate, rtThDense, rtTheadSticky } from '../../../Components/recordTableClasses';

const props = defineProps({
    menu: { type: Array, default: () => [] },
    paginationMeta: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    current: { type: Object, default: null },
    messages: { type: Object, required: true },
    t: { type: Function, required: true },
    translateMenuTitle: { type: Function, required: true },
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
    openLinkedCell: { type: Function, required: true },
});

const cellHref = (row, field) => {
    const href = row?.[`${field}_href`];

    return typeof href === 'string' && href.trim() !== '' ? href.trim() : null;
};

const isRowDeleted = (row) => {
    const value = row?.is_deleted;

    return value === true || value === 1 || value === '1';
};

const rowClass = (row) => {
    const selected = String(props.selectedId) === String(props.rowPrimaryKey(row));
    const deleted = isRowDeleted(row);

    if (deleted && selected) {
        return 'bg-stone-200/90 text-stone-400 dark:bg-slate-800/80 dark:text-slate-500';
    }

    if (deleted) {
        return 'bg-stone-100/90 text-stone-400 hover:bg-stone-100 dark:bg-slate-800/50 dark:text-slate-500 dark:hover:bg-slate-800/60';
    }

    if (selected) {
        return 'bg-brand-soft/80 dark:bg-brand/15';
    }

    return 'hover:bg-stone-50 dark:hover:bg-slate-800/50';
};
</script>

<template>
    <section class="ui-page lg:!h-full">
        <div v-if="menu.length === 0" class="ui-empty">
            {{ t(messages, 'menuEmpty') }}
        </div>
        <template v-else>
            <div class="ui-enter shrink-0 space-y-3 pb-3">
                <div class="flex min-w-0 flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h1 class="ui-section-title truncate">
                            {{ current?.key ? translateMenuTitle(current.key, current.title) : t(messages, 'directoryFallback') }}
                        </h1>
                        <p class="ui-muted mt-0.5 text-xs">
                            <template v-if="paginationMeta.from != null && paginationMeta.to != null">
                                {{ paginationMeta.from }}–{{ paginationMeta.to }} ·
                            </template>
                            {{ paginationMeta.total ?? 0 }} {{ t(messages, 'totalRows') }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                        <span v-if="loading" class="ui-chip bg-brand-soft text-brand dark:bg-brand/20 dark:text-brand">
                            {{ t(messages, 'loading') }}
                        </span>
                        <button
                            v-if="isUsersDirectory && canManageRoles"
                            type="button"
                            class="ui-btn ui-btn-secondary"
                            @click="goRoles"
                        >
                            {{ t(messages, 'goRoles') }}
                        </button>
                        <button
                            v-if="isRolesDirectory && canManageUsers"
                            type="button"
                            class="ui-btn ui-btn-secondary"
                            @click="goUsers"
                        >
                            {{ t(messages, 'goUsers') }}
                        </button>
                        <button
                            v-if="isUsersDirectory && canManageUsers"
                            type="button"
                            class="ui-btn ui-btn-primary"
                            @click="openCreateUserModal"
                        >
                            {{ t(messages, 'addUser') }}
                        </button>
                        <button
                            v-if="isRolesDirectory && canManageRoles"
                            type="button"
                            class="ui-btn ui-btn-primary"
                            @click="openCreateRoleModal"
                        >
                            {{ t(messages, 'addRole') }}
                        </button>
                    </div>
                </div>

                <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                    <div class="relative min-w-0 flex-1">
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.75"
                            class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-stone-400 dark:text-slate-500"
                            aria-hidden="true"
                        >
                            <circle cx="11" cy="11" r="7" />
                            <path stroke-linecap="round" d="m20 20-3-3" />
                        </svg>
                        <input
                            :value="query"
                            type="text"
                            :placeholder="t(messages, 'search')"
                            class="ui-input !pl-9"
                            @input="setQuery($event.target.value)"
                        >
                    </div>
                    <div
                        v-if="rows.length > 0 || (paginationMeta.last_page ?? 1) > 1"
                        class="flex shrink-0 items-center gap-1.5"
                    >
                        <button
                            type="button"
                            class="ui-btn ui-btn-secondary !px-2.5"
                            :disabled="(paginationMeta.current_page ?? 1) <= 1 || loading"
                            @click="goPage((paginationMeta.current_page ?? 1) - 1)"
                        >
                            {{ t(messages, 'prev') }}
                        </button>
                        <span class="min-w-[4.5rem] text-center text-xs font-medium text-stone-600 dark:text-slate-300">
                            {{ paginationMeta.current_page ?? 1 }} / {{ paginationMeta.last_page ?? 1 }}
                        </span>
                        <button
                            type="button"
                            class="ui-btn ui-btn-secondary !px-2.5"
                            :disabled="(paginationMeta.current_page ?? 1) >= (paginationMeta.last_page ?? 1) || loading"
                            @click="goPage((paginationMeta.current_page ?? 1) + 1)"
                        >
                            {{ t(messages, 'next') }}
                        </button>
                    </div>
                </div>
            </div>

            <div
                class="relative min-h-0 min-w-0 flex-1 overflow-hidden rounded-xl border border-line shadow-sm">
                <button v-show="showTableScrollLeft" type="button"
                    class="absolute left-0 top-0 z-20 flex h-full w-9 shrink-0 items-center justify-center border-0 bg-gradient-to-r from-page via-page/90 to-transparent py-2 pl-1 pr-2 text-ink-muted shadow-none transition hover:text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-brand"
                    :aria-label="t(messages, 'scrollTableLeft')" @mouseenter="startAutoTableScroll(-1)"
                    @mouseleave="stopAutoTableScroll" @click="nudgeTableScroll(-1)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" class="h-6 w-6" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 6l-6 6 6 6" />
                    </svg>
                </button>
                <button v-show="showTableScrollRight" type="button"
                    class="absolute right-0 top-0 z-20 flex h-full w-9 shrink-0 items-center justify-center border-0 bg-gradient-to-l from-page via-page/90 to-transparent py-2 pl-2 pr-1 text-ink-muted shadow-none transition hover:text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-brand"
                    :aria-label="t(messages, 'scrollTableRight')" @mouseenter="startAutoTableScroll(1)"
                    @mouseleave="stopAutoTableScroll" @click="nudgeTableScroll(1)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" class="h-6 w-6" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" />
                    </svg>
                </button>
                <div :ref="setTableScrollRef" class="ui-scroll h-full min-h-0 overflow-auto overscroll-x-contain"
                    @scroll.passive="updateTableScrollHints">
                    <RecordTable variant="scroll">
                        <thead :class="rtTheadSticky">
                            <Draggable :model-value="orderedFields" tag="tr" :item-key="fieldItemKey"
                                handle=".col-drag-handle" :animation="200"
                                ghost-class="!bg-brand-soft" class="bg-surface-muted/95"
                                @update:model-value="setOrderedFields" @end="persistColumnOrder">
                                <template #item="{ element: field }">
                                    <th :class="rtThDense">
                                        <div class="flex min-w-0 items-center gap-0.5">
                                            <button type="button"
                                                class="col-drag-handle shrink-0 cursor-grab touch-manipulation rounded p-0.5 text-stone-400 hover:bg-stone-200 hover:text-stone-700 active:cursor-grabbing dark:hover:bg-slate-600 dark:hover:text-slate-200"
                                                :title="t(messages, 'dragColumn')"
                                                :aria-label="t(messages, 'dragColumn')" @click.stop>
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                                    fill="currentColor" class="h-4 w-4 shrink-0" aria-hidden="true">
                                                    <path
                                                        d="M8 6a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm0 6a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm-2 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm8-14a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm-2 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm6-12a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm-2 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z" />
                                                </svg>
                                            </button>
                                            <button type="button"
                                                class="min-w-0 flex-1 cursor-pointer px-1 text-left transition hover:text-stone-800 dark:hover:text-slate-100"
                                                @click="toggleSort(field)">
                                                <span class="inline-flex min-w-0 items-center gap-1">
                                                    <span class="truncate">{{ field }}</span>
                                                    <span v-if="sortField === field"
                                                        class="shrink-0 text-[10px] font-bold text-brand"
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
                                class="cursor-pointer transition"
                                :class="rowClass(row)"
                                :title="isRowDeleted(row) ? t(messages, 'deletedInBitrix') : undefined"
                                @click="markRowSelected(row)" @dblclick="handleRowDblClick(row)">
                                <td v-for="field in orderedFields" :key="`${rowPrimaryKey(row)}-${field}`"
                                    :class="rtTdTruncate">
                                    <button
                                        v-if="cellHref(row, field)"
                                        type="button"
                                        class="max-w-full cursor-pointer truncate text-left underline underline-offset-2"
                                        :class="isRowDeleted(row)
                                            ? 'text-stone-400 decoration-stone-300 hover:text-stone-500 dark:text-slate-500 dark:decoration-slate-600'
                                            : 'text-brand decoration-brand-muted hover:text-brand-hover dark:text-brand dark:decoration-brand/40'"
                                        @click.stop="openLinkedCell(cellHref(row, field))"
                                        v-html="highlightHtml(row[field], query)"
                                    ></button>
                                    <span v-else v-html="highlightHtml(row[field], query)"></span>
                                </td>
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
