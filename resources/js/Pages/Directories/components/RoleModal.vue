<script setup>
defineProps({
    roleModalOpen: { type: Boolean, default: false },
    editingRoleId: { type: [String, Number, null], default: null },
    roleAdminSaving: { type: Boolean, default: false },
    roleAdminError: { type: String, default: '' },
    roleForm: { type: Object, required: true },
    permissionOptions: { type: Array, default: () => [] },
    messages: { type: Object, required: true },
    t: { type: Function, required: true },
    closeRoleModal: { type: Function, required: true },
    toggleRolePermission: { type: Function, required: true },
    deleteRoleFromModal: { type: Function, required: true },
    submitRole: { type: Function, required: true },
});
</script>

<template>
    <div
        v-if="roleModalOpen"
        class="fixed inset-0 z-[85] flex items-center justify-center bg-black/40 p-4"
        @click.self="closeRoleModal"
    >
        <div class="max-h-[90vh] w-full max-w-lg overflow-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl dark:border-slate-600 dark:bg-slate-900">
            <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">
                {{ editingRoleId ? t(messages, 'editRole') : t(messages, 'addRole') }}
            </h2>
            <div v-if="roleAdminError" class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-100">
                {{ roleAdminError }}
            </div>
            <div class="space-y-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">{{ t(messages, 'name') }}</label>
                    <input v-model="roleForm.name" type="text" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100">
                    <p v-if="roleForm.name === 'admin'" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t(messages, 'adminRole') }}</p>
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
            <div class="mt-5 flex items-center justify-between gap-2">
                <button
                    v-if="editingRoleId && roleForm.name !== 'admin'"
                    type="button"
                    class="rounded-lg border border-red-600 px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 dark:border-red-500 dark:text-red-400 dark:hover:bg-red-950/30"
                    :disabled="roleAdminSaving"
                    @click="deleteRoleFromModal"
                >
                    {{ t(messages, 'deleteRole') }}
                </button>
                <span v-else></span>
                <div class="flex items-center gap-2">
                    <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600" :disabled="roleAdminSaving" @click="closeRoleModal">{{ t(messages, 'cancel') }}</button>
                    <button type="button" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white dark:bg-slate-100 dark:text-slate-900" :disabled="roleAdminSaving" @click="submitRole">{{ t(messages, 'save') }}</button>
                </div>
            </div>
        </div>
    </div>
</template>
