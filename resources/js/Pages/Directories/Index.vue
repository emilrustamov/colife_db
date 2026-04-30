<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Sidebar from './components/Sidebar.vue';
import TablePanel from './components/TablePanel.vue';
import CardDrawer from './components/CardDrawer.vue';
import UserModal from './components/UserModal.vue';
import RoleModal from './components/RoleModal.vue';
import { useAppPreferences } from '../../composables/useAppPreferences';
import { useUsersCrud } from './composables/useUsersCrud';
import { useDirectoryTable } from './composables/useDirectoryTable';
import { useRolesCrud } from './composables/useRolesCrud';
import { useDirectoryNavigation } from './composables/useDirectoryNavigation';
import { useDirectoryData } from './composables/useDirectoryData';
import { directoryMessages } from './i18n/directoryMessages';

const perPage = 50;
let searchDebounceId = null;
const skipSearchRefetch = ref(false);

const inertiaPage = usePage();

const props = defineProps({
    directories: {
        type: Array,
        default: () => [],
    },
    initialDirectoryKey: {
        type: String,
        default: null,
    },
});

const {
    menu,
    selectedKey,
    hiddenKeys,
    settingsOpen,
    contactsExpanded,
    visibleMenu,
    visibleTopLevelMenu,
    visibleContactsChildren,
    isContactsChildSelected,
    loadHiddenKeys,
    syncSelectedKey,
    selectDirectory,
    setItemVisible,
} = useDirectoryNavigation({
    directories: props.directories,
    router,
});
const canManageUsers = computed(() => inertiaPage.props.auth?.user?.can?.manageUsers === true);
const canManageRoles = computed(() => inertiaPage.props.auth?.user?.can?.manageRoles === true);
const currentUserId = computed(() => inertiaPage.props.auth?.user?.id ?? null);
const isUsersDirectory = computed(() => selectedKey.value === 'users');
const isRolesDirectory = computed(() => selectedKey.value === 'roles');
let tableScrollResizeObserver = null;
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

const messages = directoryMessages;

const translateMenuTitle = (key, fallback) => {
    const translated = t(messages, key);
    return translated === key ? fallback : translated;
};

const {
    selectedId,
    loading,
    rowDetailLoading,
    cardModalOpen,
    cardModalTab,
    query,
    rows,
    fields,
    form,
    timeline,
    page,
    sortField,
    sortDir,
    paginationMeta,
    directoryDetail,
    orderedFields,
    current,
    rowPrimaryKey,
    fetchRows,
    closeCardModal,
    loadList,
    toggleSort,
    goPage,
    selectRow,
    markRowSelected,
    renderValue,
} = useDirectoryData({
    selectedKey,
    menu,
    perPage,
});

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


const {
    tableScrollRef,
    showTableScrollLeft,
    showTableScrollRight,
    updateTableScrollHints,
    stopAutoTableScroll,
    startAutoTableScroll,
    nudgeTableScroll,
    setTableScrollRef,
} = useDirectoryTable();

const {
    userModalOpen,
    editingUserId,
    userForm,
    roleOptions,
    userAdminError,
    userAdminSaving,
    openCreateUserModal,
    openEditUserModal,
    closeUserModal,
    toggleUserRole,
    submitUser,
    deleteUserFromModal,
} = useUsersCrud({
    canManageUsers,
    currentUserId,
    rowPrimaryKey,
    fetchRows,
    t,
    messages,
});

const {
    roleModalOpen,
    editingRoleId,
    roleForm,
    permissionOptions,
    roleAdminError,
    roleAdminSaving,
    openCreateRoleModal,
    openEditRoleModal,
    closeRoleModal,
    toggleRolePermission,
    submitRole,
    deleteRoleFromModal,
} = useRolesCrud({
    canManageRoles,
    rowPrimaryKey,
    fetchRows,
    t,
    messages,
});


const handleRowDblClick = (row) => {
    if (isUsersDirectory.value && canManageUsers.value) {
        openEditUserModal(row);
        return;
    }

    if (isRolesDirectory.value && canManageRoles.value) {
        openEditRoleModal(row);
        return;
    }

    selectRow(row);
};


const logout = () => {
    router.post('/logout');
};

const setOrderedFields = (value) => {
    orderedFields.value = value;
};

const setQuery = (value) => {
    query.value = value;
};

const setSettingsOpen = (value) => {
    settingsOpen.value = value;
};

const setCardModalTab = (value) => {
    cardModalTab.value = value;
};

const goRoles = () => {
    router.get('/directories/roles');
};

const goUsers = () => {
    router.get('/directories/users');
};

const onSetItemVisible = (key, visible) => {
    const prevSelectedKey = selectedKey.value;
    setItemVisible(key, visible);
    syncSelectedKey();
    if (selectedKey.value !== prevSelectedKey) {
        loadList(skipSearchRefetch);
    }
};

onMounted(() => {
    initLocale();
    initTheme();
    loadHiddenKeys();
    selectedKey.value = props.initialDirectoryKey;
    syncSelectedKey();
    if (selectedKey.value) {
        loadList(skipSearchRefetch);
    }
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
        selectedKey.value = props.initialDirectoryKey;
        syncSelectedKey();
        if (selectedKey.value !== prevSelectedKey) {
            loadList(skipSearchRefetch);
        }
    },
);

watch(
    () => props.initialDirectoryKey,
    (value) => {
        const prevSelectedKey = selectedKey.value;
        selectedKey.value = value;
        syncSelectedKey();
        if (selectedKey.value !== prevSelectedKey) {
            loadList(skipSearchRefetch);
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
                <Sidebar
                    :menu="menu"
                    :visible-top-level-menu="visibleTopLevelMenu"
                    :visible-contacts-children="visibleContactsChildren"
                    :contacts-expanded="contactsExpanded"
                    :is-contacts-child-selected="isContactsChildSelected"
                    :selected-key="selectedKey"
                    :hidden-keys="hiddenKeys"
                    :settings-open="settingsOpen"
                    :inertia-page="inertiaPage"
                    :locale="locale"
                    :theme="theme"
                    :t="t"
                    :messages="messages"
                    :translate-menu-title="translateMenuTitle"
                    :set-settings-open="setSettingsOpen"
                    :set-item-visible="onSetItemVisible"
                    :select-directory="selectDirectory"
                    :toggle-locale="toggleLocale"
                    :toggle-theme="toggleTheme"
                    :logout="logout"
                />

                <TablePanel
                    :menu="menu"
                    :pagination-meta="paginationMeta"
                    :rows="rows"
                    :loading="loading"
                    :current="current"
                    :messages="messages"
                    :t="t"
                    :is-users-directory="isUsersDirectory"
                    :is-roles-directory="isRolesDirectory"
                    :can-manage-users="canManageUsers"
                    :can-manage-roles="canManageRoles"
                    :query="query"
                    :show-table-scroll-left="showTableScrollLeft"
                    :show-table-scroll-right="showTableScrollRight"
                    :ordered-fields="orderedFields"
                    :field-item-key="fieldItemKey"
                    :sort-field="sortField"
                    :sort-dir="sortDir"
                    :selected-id="selectedId"
                    :row-primary-key="rowPrimaryKey"
                    :highlight-html="highlightHtml"
                    :go-page="goPage"
                    :go-roles="goRoles"
                    :go-users="goUsers"
                    :open-create-user-modal="openCreateUserModal"
                    :open-create-role-modal="openCreateRoleModal"
                    :set-query="setQuery"
                    :start-auto-table-scroll="startAutoTableScroll"
                    :stop-auto-table-scroll="stopAutoTableScroll"
                    :nudge-table-scroll="nudgeTableScroll"
                    :update-table-scroll-hints="updateTableScrollHints"
                    :set-table-scroll-ref="setTableScrollRef"
                    :set-ordered-fields="setOrderedFields"
                    :persist-column-order="persistColumnOrder"
                    :toggle-sort="toggleSort"
                    :mark-row-selected="markRowSelected"
                    :handle-row-dbl-click="handleRowDblClick"
                />
            </div>
        </div>
        <CardDrawer
            :card-modal-open="cardModalOpen"
            :menu-length="menu.length"
            :current="current"
            :card-modal-tab="cardModalTab"
            :row-detail-loading="rowDetailLoading"
            :form="form"
            :timeline="timeline"
            :messages="messages"
            :t="t"
            :close-card-modal="closeCardModal"
            :set-card-modal-tab="setCardModalTab"
            :render-value="renderValue"
        />
        <UserModal
            :user-modal-open="userModalOpen"
            :editing-user-id="editingUserId"
            :current-user-id="currentUserId"
            :can-manage-users="canManageUsers"
            :user-admin-saving="userAdminSaving"
            :user-admin-error="userAdminError"
            :user-form="userForm"
            :role-options="roleOptions"
            :messages="messages"
            :t="t"
            :close-user-modal="closeUserModal"
            :toggle-user-role="toggleUserRole"
            :delete-user-from-modal="deleteUserFromModal"
            :submit-user="submitUser"
        />
        <RoleModal
            :role-modal-open="roleModalOpen"
            :editing-role-id="editingRoleId"
            :role-admin-saving="roleAdminSaving"
            :role-admin-error="roleAdminError"
            :role-form="roleForm"
            :permission-options="permissionOptions"
            :messages="messages"
            :t="t"
            :close-role-modal="closeRoleModal"
            :toggle-role-permission="toggleRolePermission"
            :delete-role-from-modal="deleteRoleFromModal"
            :submit-role="submitRole"
        />
    </div>
</template>
