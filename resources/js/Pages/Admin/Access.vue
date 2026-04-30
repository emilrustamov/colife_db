<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import RecordTable from '../../Components/RecordTable.vue';
import {
    rtEmpty,
    rtTdActions,
    rtTdLeading,
    rtTdMuted,
    rtTdStrong,
    rtTh,
    rtThead,
} from '../../Components/recordTableClasses';
import { useAppPreferences } from '../../composables/useAppPreferences';

const page = usePage();
const { locale, theme, initLocale, initTheme, toggleLocale, toggleTheme, t } = useAppPreferences();

const authUser = computed(() => page.props.auth?.user ?? null);
const canUsers = computed(() => page.props.auth?.user?.can?.manageUsers === true);
const canRoles = computed(() => page.props.auth?.user?.can?.manageRoles === true);

const messages = {
    ru: {
        accessTitle: 'Доступ',
        usersTab: 'Пользователи',
        rolesTab: 'Роли',
        back: 'Меню',
        logout: 'Выйти',
        lang: 'Язык',
        theme: 'Тема',
        dark: 'Тёмная',
        light: 'Светлая',
        loading: 'Загрузка...',
        addUser: 'Добавить пользователя',
        addRole: 'Добавить роль',
        name: 'Имя',
        email: 'Email',
        password: 'Пароль',
        passwordHint: 'Минимум 8 символов. Оставьте пустым, чтобы не менять.',
        roles: 'Роли',
        permissions: 'Права',
        save: 'Сохранить',
        cancel: 'Отмена',
        edit: 'Изменить',
        delete: 'Удалить',
        emptyUsers: 'Нет пользователей',
        emptyRoles: 'Нет ролей',
        self: 'это вы',
        adminRole: 'роль admin нельзя удалить',
        requestError: 'Ошибка запроса',
    },
    en: {
        accessTitle: 'Access',
        usersTab: 'Users',
        rolesTab: 'Roles',
        back: 'Menu',
        logout: 'Logout',
        lang: 'Language',
        theme: 'Theme',
        dark: 'Dark',
        light: 'Light',
        loading: 'Loading...',
        addUser: 'Add user',
        addRole: 'Add role',
        name: 'Name',
        email: 'Email',
        password: 'Password',
        passwordHint: 'At least 8 characters. Leave blank to keep current.',
        roles: 'Roles',
        permissions: 'Permissions',
        save: 'Save',
        cancel: 'Cancel',
        edit: 'Edit',
        delete: 'Delete',
        emptyUsers: 'No users',
        emptyRoles: 'No roles',
        self: 'this is you',
        adminRole: 'admin role cannot be deleted',
        requestError: 'Request failed',
    },
};

const activeTab = ref('users');
const loading = ref(false);
const apiError = ref('');

const users = ref([]);
const roleOptions = ref([]);

const roles = ref([]);
const permissionOptions = ref([]);

const userModalOpen = ref(false);
const editingUserId = ref(null);
const userForm = ref({ name: '', email: '', password: '', role_ids: [] });

const roleModalOpen = ref(false);
const editingRoleId = ref(null);
const roleForm = ref({ name: '', permission_ids: [] });

const currentUserId = computed(() => page.props.auth?.user?.id ?? null);

const editingAdminRole = computed(() => {
    if (!editingRoleId.value) {
        return false;
    }
    return roles.value.some((r) => r.id === editingRoleId.value && r.name === 'admin');
});

const setTab = (tab) => {
    activeTab.value = tab;
    apiError.value = '';
    if (tab === 'users' && canUsers.value) {
        loadUsers();
    }
    if (tab === 'roles' && canRoles.value) {
        loadRoles();
    }
};

const loadUsers = async () => {
    if (!canUsers.value) {
        return;
    }
    loading.value = true;
    apiError.value = '';
    try {
        const { data } = await window.axios.get('/api/admin/users');
        users.value = data.users ?? [];
        roleOptions.value = data.roles ?? [];
    } catch (e) {
        apiError.value = t(messages, 'requestError');
    } finally {
        loading.value = false;
    }
};

const loadRoles = async () => {
    if (!canRoles.value) {
        return;
    }
    loading.value = true;
    apiError.value = '';
    try {
        const { data } = await window.axios.get('/api/admin/roles');
        roles.value = data.roles ?? [];
        permissionOptions.value = data.permissions ?? [];
    } catch (e) {
        apiError.value = t(messages, 'requestError');
    } finally {
        loading.value = false;
    }
};

const openUserModal = (user = null) => {
    apiError.value = '';
    if (user) {
        editingUserId.value = user.id;
        userForm.value = {
            name: user.name,
            email: user.email,
            password: '',
            role_ids: user.roles.map((r) => r.id),
        };
    } else {
        editingUserId.value = null;
        userForm.value = { name: '', email: '', password: '', role_ids: [] };
    }
    userModalOpen.value = true;
};

const closeUserModal = () => {
    userModalOpen.value = false;
};

const submitUser = async () => {
    apiError.value = '';
    loading.value = true;
    try {
        if (editingUserId.value) {
            const payload = {
                name: userForm.value.name,
                email: userForm.value.email,
                role_ids: userForm.value.role_ids,
            };
            if (userForm.value.password) {
                payload.password = userForm.value.password;
            }
            await window.axios.put(`/api/admin/users/${editingUserId.value}`, payload);
        } else {
            await window.axios.post('/api/admin/users', {
                name: userForm.value.name,
                email: userForm.value.email,
                password: userForm.value.password,
                role_ids: userForm.value.role_ids,
            });
        }
        closeUserModal();
        await loadUsers();
    } catch (e) {
        const msg = e.response?.data?.message;
        const first = e.response?.data?.errors ? Object.values(e.response.data.errors).flat()[0] : null;
        apiError.value = first || msg || t(messages, 'requestError');
    } finally {
        loading.value = false;
    }
};

const deleteUser = async (user) => {
    if (user.id === currentUserId.value) {
        return;
    }
    if (!window.confirm(user.email)) {
        return;
    }
    loading.value = true;
    apiError.value = '';
    try {
        await window.axios.delete(`/api/admin/users/${user.id}`);
        await loadUsers();
    } catch (e) {
        apiError.value = t(messages, 'requestError');
    } finally {
        loading.value = false;
    }
};

const toggleUserRole = (roleId) => {
    const ids = [...userForm.value.role_ids];
    const i = ids.indexOf(roleId);
    if (i === -1) {
        ids.push(roleId);
    } else {
        ids.splice(i, 1);
    }
    userForm.value.role_ids = ids;
};

const openRoleModal = (role = null) => {
    apiError.value = '';
    if (role) {
        editingRoleId.value = role.id;
        roleForm.value = {
            name: role.name,
            permission_ids: role.permissions.map((p) => p.id),
        };
    } else {
        editingRoleId.value = null;
        roleForm.value = { name: '', permission_ids: [] };
    }
    roleModalOpen.value = true;
};

const closeRoleModal = () => {
    roleModalOpen.value = false;
};

const submitRole = async () => {
    apiError.value = '';
    loading.value = true;
    try {
        if (editingRoleId.value) {
            await window.axios.put(`/api/admin/roles/${editingRoleId.value}`, {
                name: roleForm.value.name,
                permission_ids: roleForm.value.permission_ids,
            });
        } else {
            await window.axios.post('/api/admin/roles', {
                name: roleForm.value.name,
                permission_ids: roleForm.value.permission_ids,
            });
        }
        closeRoleModal();
        await loadRoles();
        if (canUsers.value) {
            await loadUsers();
        }
    } catch (e) {
        const first = e.response?.data?.errors ? Object.values(e.response.data.errors).flat()[0] : null;
        apiError.value = first || t(messages, 'requestError');
    } finally {
        loading.value = false;
    }
};

const deleteRole = async (role) => {
    if (role.name === 'admin') {
        return;
    }
    if (!window.confirm(role.name)) {
        return;
    }
    loading.value = true;
    apiError.value = '';
    try {
        await window.axios.delete(`/api/admin/roles/${role.id}`);
        await loadRoles();
        if (canUsers.value) {
            await loadUsers();
        }
    } catch (e) {
        apiError.value = t(messages, 'requestError');
    } finally {
        loading.value = false;
    }
};

const toggleRolePermission = (permissionId) => {
    const ids = [...roleForm.value.permission_ids];
    const i = ids.indexOf(permissionId);
    if (i === -1) {
        ids.push(permissionId);
    } else {
        ids.splice(i, 1);
    }
    roleForm.value.permission_ids = ids;
};

const logout = () => {
    router.post('/logout');
};

onMounted(() => {
    initLocale();
    initTheme();
    if (canUsers.value) {
        activeTab.value = 'users';
        loadUsers();
    } else if (canRoles.value) {
        activeTab.value = 'roles';
        loadRoles();
    }
});
</script>

<template>
    <div class="min-h-screen bg-slate-100 p-4 md:p-6 dark:bg-slate-950">
        <div class="mx-auto max-w-[1100px] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-700 md:px-6">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t(messages, 'accessTitle') }}</h1>
                    <Link
                        href="/directories"
                        class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                    >
                        {{ t(messages, 'back') }}
                    </Link>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <div v-if="authUser" class="flex flex-col items-end text-right">
                        <span class="max-w-[200px] truncate text-xs font-medium text-slate-900 dark:text-slate-100">{{ authUser.name }}</span>
                        <span class="max-w-[200px] truncate text-[11px] text-slate-500 dark:text-slate-400">{{ authUser.email }}</span>
                        <button
                            type="button"
                            class="mt-1.5 rounded-lg border border-red-600 bg-red-600 px-2.5 py-1 text-[11px] font-medium text-white transition hover:bg-red-700 dark:border-red-500 dark:bg-red-500 dark:hover:bg-red-600"
                            @click="logout"
                        >
                            {{ t(messages, 'logout') }}
                        </button>
                    </div>
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
                            class="h-4 w-4 text-yellow-500"
                            aria-hidden="true"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
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
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1 1 11.21 3a7 7 0 0 0 9.79 9.79Z" />
                        </svg>
                    </button>
                    <span v-if="loading" class="text-xs text-slate-500 dark:text-slate-400">{{ t(messages, 'loading') }}</span>
                </div>
            </header>

            <div v-if="apiError" class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/40 dark:text-amber-100">
                {{ apiError }}
            </div>

            <div class="border-b border-slate-200 px-4 dark:border-slate-700 md:px-6">
                <nav class="flex gap-1 py-2">
                    <button
                        v-if="canUsers"
                        type="button"
                        class="rounded-lg px-3 py-2 text-sm font-medium transition"
                        :class="activeTab === 'users' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
                        @click="setTab('users')"
                    >
                        {{ t(messages, 'usersTab') }}
                    </button>
                    <button
                        v-if="canRoles"
                        type="button"
                        class="rounded-lg px-3 py-2 text-sm font-medium transition"
                        :class="activeTab === 'roles' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
                        @click="setTab('roles')"
                    >
                        {{ t(messages, 'rolesTab') }}
                    </button>
                </nav>
            </div>

            <div v-if="activeTab === 'users' && canUsers" class="p-4 md:p-6">
                <div class="mb-4 flex justify-end">
                    <button
                        type="button"
                        class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white dark:bg-slate-100 dark:text-slate-900"
                        @click="openUserModal()"
                    >
                        {{ t(messages, 'addUser') }}
                    </button>
                </div>
                <div class="overflow-auto rounded-lg border border-slate-200 dark:border-slate-700">
                    <RecordTable variant="fluid">
                        <thead :class="rtThead">
                            <tr>
                                <th :class="rtTh('left')">{{ t(messages, 'name') }}</th>
                                <th :class="rtTh('left')">{{ t(messages, 'email') }}</th>
                                <th :class="rtTh('left')">{{ t(messages, 'roles') }}</th>
                                <th :class="rtTh('right')"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="u in users" :key="u.id">
                                <td :class="rtTdLeading">{{ u.name }}</td>
                                <td :class="rtTdMuted">
                                    {{ u.email }}
                                    <span v-if="u.id === currentUserId" class="ml-1 text-xs text-slate-400">({{ t(messages, 'self') }})</span>
                                </td>
                                <td :class="rtTdMuted">
                                    <span v-for="r in u.roles" :key="r.id" class="mr-1 inline-block rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-slate-800">{{ r.name }}</span>
                                </td>
                                <td :class="rtTdActions">
                                    <button type="button" class="mr-2 text-blue-600 hover:underline dark:text-blue-400" @click="openUserModal(u)">{{ t(messages, 'edit') }}</button>
                                    <button
                                        type="button"
                                        class="text-red-600 hover:underline disabled:opacity-40 dark:text-red-400"
                                        :disabled="u.id === currentUserId"
                                        @click="deleteUser(u)"
                                    >
                                        {{ t(messages, 'delete') }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="users.length === 0 && !loading">
                                <td colspan="4" :class="rtEmpty">{{ t(messages, 'emptyUsers') }}</td>
                            </tr>
                        </tbody>
                    </RecordTable>
                </div>
            </div>

            <div v-if="activeTab === 'roles' && canRoles" class="p-4 md:p-6">
                <div class="mb-4 flex justify-end">
                    <button
                        type="button"
                        class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white dark:bg-slate-100 dark:text-slate-900"
                        @click="openRoleModal()"
                    >
                        {{ t(messages, 'addRole') }}
                    </button>
                </div>
                <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">{{ t(messages, 'adminRole') }}</p>
                <div class="overflow-auto rounded-lg border border-slate-200 dark:border-slate-700">
                    <RecordTable variant="fluid">
                        <thead :class="rtThead">
                            <tr>
                                <th :class="rtTh('left')">{{ t(messages, 'name') }}</th>
                                <th :class="rtTh('left')">{{ t(messages, 'permissions') }}</th>
                                <th :class="rtTh('right')"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in roles" :key="r.id">
                                <td :class="rtTdStrong">{{ r.name }}</td>
                                <td :class="rtTdMuted">
                                    <span v-for="p in r.permissions" :key="p.id" class="mr-1 mb-1 inline-block rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-slate-800">{{ p.name }}</span>
                                </td>
                                <td :class="rtTdActions">
                                    <button type="button" class="mr-2 text-blue-600 hover:underline dark:text-blue-400" @click="openRoleModal(r)">{{ t(messages, 'edit') }}</button>
                                    <button
                                        type="button"
                                        class="text-red-600 hover:underline disabled:opacity-40 dark:text-red-400"
                                        :disabled="r.name === 'admin'"
                                        @click="deleteRole(r)"
                                    >
                                        {{ t(messages, 'delete') }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="roles.length === 0 && !loading">
                                <td colspan="3" :class="rtEmpty">{{ t(messages, 'emptyRoles') }}</td>
                            </tr>
                        </tbody>
                    </RecordTable>
                </div>
            </div>

            <div
                v-if="userModalOpen"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                @click.self="closeUserModal"
            >
                <div class="max-h-[90vh] w-full max-w-md overflow-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl dark:border-slate-600 dark:bg-slate-900">
                    <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ editingUserId ? t(messages, 'edit') : t(messages, 'addUser') }}</h2>
                    <div class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">{{ t(messages, 'name') }}</label>
                            <input v-model="userForm.name" type="text" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">{{ t(messages, 'email') }}</label>
                            <input v-model="userForm.email" type="email" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">{{ t(messages, 'password') }}</label>
                            <input v-model="userForm.password" type="password" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100">
                            <p v-if="editingUserId" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t(messages, 'passwordHint') }}</p>
                        </div>
                        <div>
                            <label class="mb-2 block text-xs font-medium text-slate-600 dark:text-slate-400">{{ t(messages, 'roles') }}</label>
                            <div class="max-h-40 space-y-2 overflow-auto rounded-lg border border-slate-200 p-2 dark:border-slate-700">
                                <label v-for="opt in roleOptions" :key="opt.id" class="flex cursor-pointer items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                                    <input type="checkbox" :checked="userForm.role_ids.includes(opt.id)" @change="toggleUserRole(opt.id)">
                                    <span>{{ opt.name }}</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600" @click="closeUserModal">{{ t(messages, 'cancel') }}</button>
                        <button type="button" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white dark:bg-slate-100 dark:text-slate-900" @click="submitUser">{{ t(messages, 'save') }}</button>
                    </div>
                </div>
            </div>

            <div
                v-if="roleModalOpen"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                @click.self="closeRoleModal"
            >
                <div class="max-h-[90vh] w-full max-w-lg overflow-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl dark:border-slate-600 dark:bg-slate-900">
                    <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ editingRoleId ? t(messages, 'edit') : t(messages, 'addRole') }}</h2>
                    <div class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">{{ t(messages, 'name') }}</label>
                            <input
                                v-model="roleForm.name"
                                type="text"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                                :disabled="editingAdminRole"
                            >
                        </div>
                        <div>
                            <label class="mb-2 block text-xs font-medium text-slate-600 dark:text-slate-400">{{ t(messages, 'permissions') }}</label>
                            <div class="max-h-52 space-y-2 overflow-auto rounded-lg border border-slate-200 p-2 dark:border-slate-700">
                                <label v-for="opt in permissionOptions" :key="opt.id" class="flex cursor-pointer items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                                    <input type="checkbox" :checked="roleForm.permission_ids.includes(opt.id)" @change="toggleRolePermission(opt.id)">
                                    <span>{{ opt.name }}</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600" @click="closeRoleModal">{{ t(messages, 'cancel') }}</button>
                        <button type="button" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white dark:bg-slate-100 dark:text-slate-900" @click="submitRole">{{ t(messages, 'save') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
