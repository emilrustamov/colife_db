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
        class="ui-overlay z-[85] flex items-center justify-center p-4"
        @click.self="closeRoleModal"
    >
        <div class="ui-modal ui-scroll max-w-lg">
            <h2 class="ui-section-title mb-4 !text-lg">
                {{ editingRoleId ? t(messages, 'editRole') : t(messages, 'addRole') }}
            </h2>
            <div v-if="roleAdminError" class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-100">
                {{ roleAdminError }}
            </div>
            <div class="space-y-3">
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t(messages, 'name') }}</label>
                    <input v-model="roleForm.name" type="text" class="ui-input">
                    <p v-if="roleForm.name === 'admin'" class="ui-muted mt-1 text-xs">{{ t(messages, 'adminRole') }}</p>
                </div>
                <div>
                    <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t(messages, 'permissions') }}</label>
                    <div class="ui-scroll max-h-52 space-y-1 overflow-auto rounded-xl border border-line p-2">
                        <label v-for="opt in permissionOptions" :key="opt.id" class="flex cursor-pointer items-center gap-2 rounded-lg px-1.5 py-1 text-sm text-ink hover:bg-surface-muted">
                            <input type="checkbox" class="ui-check" :checked="roleForm.permission_ids.includes(opt.id)" @change="toggleRolePermission(opt.id)">
                            <span>{{ opt.name }}</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="mt-5 flex items-center justify-between gap-2">
                <button
                    v-if="editingRoleId && roleForm.name !== 'admin'"
                    type="button"
                    class="ui-btn ui-btn-danger"
                    :disabled="roleAdminSaving"
                    @click="deleteRoleFromModal"
                >
                    {{ t(messages, 'deleteRole') }}
                </button>
                <span v-else></span>
                <div class="flex items-center gap-2">
                    <button type="button" class="ui-btn ui-btn-secondary" :disabled="roleAdminSaving" @click="closeRoleModal">{{ t(messages, 'cancel') }}</button>
                    <button type="button" class="ui-btn ui-btn-primary" :disabled="roleAdminSaving" @click="submitRole">{{ t(messages, 'save') }}</button>
                </div>
            </div>
        </div>
    </div>
</template>
