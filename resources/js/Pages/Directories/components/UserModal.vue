<script setup>
defineProps({
    userModalOpen: { type: Boolean, default: false },
    editingUserId: { type: [String, Number, null], default: null },
    currentUserId: { type: [String, Number, null], default: null },
    canManageUsers: { type: Boolean, default: false },
    userAdminSaving: { type: Boolean, default: false },
    userAdminError: { type: String, default: '' },
    userForm: { type: Object, required: true },
    roleOptions: { type: Array, default: () => [] },
    messages: { type: Object, required: true },
    t: { type: Function, required: true },
    closeUserModal: { type: Function, required: true },
    toggleUserRole: { type: Function, required: true },
    deleteUserFromModal: { type: Function, required: true },
    submitUser: { type: Function, required: true },
});
</script>

<template>
    <div
        v-if="userModalOpen"
        class="fixed inset-0 z-[80] flex items-center justify-center bg-black/40 p-4"
        @click.self="closeUserModal"
    >
        <div class="max-h-[90vh] w-full max-w-md overflow-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl dark:border-slate-600 dark:bg-slate-900">
            <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">
                {{ editingUserId ? t(messages, 'editUser') : t(messages, 'addUser') }}
            </h2>
            <div v-if="userAdminError" class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-100">
                {{ userAdminError }}
            </div>
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
            <div class="mt-5 flex items-center justify-between gap-2">
                <button
                    v-if="editingUserId && editingUserId !== currentUserId && canManageUsers"
                    type="button"
                    class="rounded-lg border border-red-600 px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 dark:border-red-500 dark:text-red-400 dark:hover:bg-red-950/30"
                    :disabled="userAdminSaving"
                    @click="deleteUserFromModal"
                >
                    {{ t(messages, 'deleteUser') }}
                </button>
                <span v-else></span>
                <div class="flex items-center gap-2">
                    <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600" :disabled="userAdminSaving" @click="closeUserModal">{{ t(messages, 'cancel') }}</button>
                    <button type="button" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white dark:bg-slate-100 dark:text-slate-900" :disabled="userAdminSaving" @click="submitUser">{{ t(messages, 'save') }}</button>
                </div>
            </div>
        </div>
    </div>
</template>
