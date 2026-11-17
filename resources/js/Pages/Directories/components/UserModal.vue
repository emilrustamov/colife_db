<script setup>
defineProps({
    userModalOpen: { type: Boolean, default: false },
    editingUserId: { type: [String, Number, null], default: null },
    currentUserId: { type: [String, Number, null], default: null },
    canEditUsers: { type: Boolean, default: false },
    canDeleteUsers: { type: Boolean, default: false },
    userAdminSaving: { type: Boolean, default: false },
    userAdminError: { type: String, default: '' },
    userForm: { type: Object, required: true },
    roleOptions: { type: Array, default: () => [] },
    messages: { type: Object, required: true },
    t: { type: Function, required: true },
    closeUserModal: { type: Function, required: true },
    setUserRole: { type: Function, required: true },
    deleteUserFromModal: { type: Function, required: true },
    submitUser: { type: Function, required: true },
});
</script>

<template>
    <Transition
        enter-active-class="transition-opacity duration-300 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-opacity duration-200 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div
            v-if="userModalOpen"
            class="ui-overlay z-[80]"
            aria-hidden="true"
            @click.self="closeUserModal"
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
            v-if="userModalOpen"
            class="fixed right-0 top-0 z-[90] flex h-full w-[min(92vw,32rem)] min-w-[18rem] flex-col border-l border-line bg-surface shadow-[-12px_0_40px_rgba(28,25,23,0.12)] dark:shadow-[-12px_0_40px_rgba(0,0,0,0.45)]"
            role="dialog"
            aria-modal="true"
            :aria-label="editingUserId ? t(messages, 'editUser') : t(messages, 'addUser')"
        >
            <div class="flex shrink-0 items-center justify-between gap-3 border-b border-line px-4 py-3.5">
                <h2 class="min-w-0 truncate text-lg font-bold tracking-tight text-ink">
                    {{ editingUserId ? t(messages, 'editUser') : t(messages, 'addUser') }}
                </h2>
                <button type="button" class="ui-btn ui-btn-secondary shrink-0" :disabled="userAdminSaving" @click="closeUserModal">
                    {{ t(messages, 'closeCard') }}
                </button>
            </div>

            <div class="ui-scroll min-h-0 flex-1 overflow-y-auto px-4 py-4">
                <div v-if="userAdminError" class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-100">
                    {{ userAdminError }}
                </div>
                <div class="space-y-4">
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
                        <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t(messages, 'role') }}</label>
                        <div class="space-y-1 rounded-xl border border-line p-2">
                            <label v-for="opt in roleOptions" :key="opt.id" class="flex cursor-pointer items-center gap-2 rounded-lg px-1.5 py-1.5 text-sm text-ink hover:bg-surface-muted">
                                <input
                                    type="radio"
                                    class="ui-check"
                                    name="user-role"
                                    :checked="userForm.role_id === opt.id"
                                    @change="setUserRole(opt.id)"
                                >
                                <span>{{ opt.name }}</span>
                            </label>
                        </div>
                        <p class="ui-muted mt-1 text-xs">{{ t(messages, 'roleHint') }}</p>
                    </div>
                </div>
            </div>

            <div class="flex shrink-0 items-center justify-between gap-2 border-t border-line px-4 py-3">
                <button
                    v-if="editingUserId && editingUserId !== currentUserId && canDeleteUsers"
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
                    <button
                        v-if="!editingUserId || canEditUsers"
                        type="button"
                        class="ui-btn ui-btn-primary"
                        :disabled="userAdminSaving"
                        @click="submitUser"
                    >
                        {{ t(messages, 'save') }}
                    </button>
                </div>
            </div>
        </aside>
    </Transition>
</template>
