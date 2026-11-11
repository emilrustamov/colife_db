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
        class="ui-overlay z-[80] flex items-center justify-center p-4"
        @click.self="closeUserModal"
    >
        <div class="ui-modal ui-scroll max-w-md">
            <h2 class="ui-section-title mb-4 !text-lg">
                {{ editingUserId ? t(messages, 'editUser') : t(messages, 'addUser') }}
            </h2>
            <div v-if="userAdminError" class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-100">
                {{ userAdminError }}
            </div>
            <div class="space-y-3">
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t(messages, 'name') }}</label>
                    <input v-model="userForm.name" type="text" class="ui-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t(messages, 'email') }}</label>
                    <input v-model="userForm.email" type="email" class="ui-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t(messages, 'password') }}</label>
                    <input v-model="userForm.password" type="password" class="ui-input">
                    <p v-if="editingUserId" class="ui-muted mt-1 text-xs">{{ t(messages, 'passwordHint') }}</p>
                </div>
                <div>
                    <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t(messages, 'roles') }}</label>
                    <div class="ui-scroll max-h-40 space-y-1 overflow-auto rounded-xl border border-line p-2">
                        <label v-for="opt in roleOptions" :key="opt.id" class="flex cursor-pointer items-center gap-2 rounded-lg px-1.5 py-1 text-sm text-ink hover:bg-surface-muted">
                            <input type="checkbox" class="ui-check" :checked="userForm.role_ids.includes(opt.id)" @change="toggleUserRole(opt.id)">
                            <span>{{ opt.name }}</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="mt-5 flex items-center justify-between gap-2">
                <button
                    v-if="editingUserId && editingUserId !== currentUserId && canManageUsers"
                    type="button"
                    class="ui-btn ui-btn-danger"
                    :disabled="userAdminSaving"
                    @click="deleteUserFromModal"
                >
                    {{ t(messages, 'deleteUser') }}
                </button>
                <span v-else></span>
                <div class="flex items-center gap-2">
                    <button type="button" class="ui-btn ui-btn-secondary" :disabled="userAdminSaving" @click="closeUserModal">{{ t(messages, 'cancel') }}</button>
                    <button type="button" class="ui-btn ui-btn-primary" :disabled="userAdminSaving" @click="submitUser">{{ t(messages, 'save') }}</button>
                </div>
            </div>
        </div>
    </div>
</template>
