<script setup>
import { computed } from 'vue';

const props = defineProps({
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
    setRolePermissionGroup: { type: Function, required: true },
    deleteRoleFromModal: { type: Function, required: true },
    submitRole: { type: Function, required: true },
});

const permissionLabel = (opt) => {
    const keyed = `permission.${opt.name}`;
    const keyedTranslated = props.t(props.messages, keyed);
    if (keyedTranslated !== keyed) {
        return keyedTranslated;
    }

    if (opt.directory_key) {
        const translated = props.t(props.messages, opt.directory_key);
        const title = translated !== opt.directory_key ? translated : (opt.label || opt.name);
        return `${props.t(props.messages, 'permissionViewPrefix')} ${title}`;
    }

    return opt.label || opt.name;
};

const permissionGroups = computed(() => {
    const admin = [];
    const catalog = [];
    const olBot = [];

    for (const opt of props.permissionOptions) {
        if (opt.group === 'catalog') {
            catalog.push(opt);
        } else if (opt.group === 'ol-bot') {
            olBot.push(opt);
        } else {
            admin.push(opt);
        }
    }

    return [
        { key: 'admin', titleKey: 'permissionGroupAdmin', items: admin },
        { key: 'catalog', titleKey: 'permissionGroupCatalog', items: catalog },
        { key: 'ol-bot', titleKey: 'permissionGroupOlBot', items: olBot },
    ].filter((group) => group.items.length > 0);
});

const allPermissionIds = computed(() => props.permissionOptions.map((opt) => opt.id));

const isGroupSelected = (items) =>
    items.length > 0 && items.every((opt) => props.roleForm.permission_ids.includes(opt.id));

const isAllSelected = computed(() => isGroupSelected(props.permissionOptions));

const toggleGroup = (items, checked) => {
    props.setRolePermissionGroup(items.map((opt) => opt.id), checked);
};

const toggleAll = (checked) => {
    props.setRolePermissionGroup(allPermissionIds.value, checked);
};
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
            v-if="roleModalOpen"
            class="ui-overlay z-[85]"
            aria-hidden="true"
            @click.self="closeRoleModal"
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
            v-if="roleModalOpen"
            class="fixed right-0 top-0 z-[95] flex h-full w-[min(92vw,40rem)] min-w-[18rem] flex-col border-l border-line bg-surface shadow-[-12px_0_40px_rgba(28,25,23,0.12)] dark:shadow-[-12px_0_40px_rgba(0,0,0,0.45)]"
            role="dialog"
            aria-modal="true"
            :aria-label="editingRoleId ? t(messages, 'editRole') : t(messages, 'addRole')"
        >
            <div class="flex shrink-0 items-center justify-between gap-3 border-b border-line px-4 py-3.5">
                <h2 class="min-w-0 truncate text-lg font-bold tracking-tight text-ink">
                    {{ editingRoleId ? t(messages, 'editRole') : t(messages, 'addRole') }}
                </h2>
                <button type="button" class="ui-btn ui-btn-secondary shrink-0" :disabled="roleAdminSaving" @click="closeRoleModal">
                    {{ t(messages, 'closeCard') }}
                </button>
            </div>

            <div class="ui-scroll min-h-0 flex-1 overflow-y-auto px-4 py-4">
                <div v-if="roleAdminError" class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-100">
                    {{ roleAdminError }}
                </div>
                <div class="space-y-4">
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t(messages, 'name') }}</label>
                        <input v-model="roleForm.name" type="text" class="ui-input">
                        <p v-if="roleForm.name === 'admin'" class="ui-muted mt-1 text-xs">{{ t(messages, 'adminRole') }}</p>
                    </div>
                    <div>
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <label class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t(messages, 'permissions') }}</label>
                            <label class="inline-flex cursor-pointer items-center gap-2 text-xs text-ink-muted">
                                <input
                                    type="checkbox"
                                    class="ui-check"
                                    :checked="isAllSelected"
                                    @change="toggleAll($event.target.checked)"
                                >
                                <span>{{ t(messages, 'selectAllPermissions') }}</span>
                            </label>
                        </div>
                        <p class="ui-muted mb-2 text-xs">{{ t(messages, 'permissionsHint') }}</p>
                        <div class="space-y-4 rounded-xl border border-line p-2">
                            <div v-for="group in permissionGroups" :key="group.key">
                                <div class="mb-1 flex items-center justify-between gap-2 px-1.5">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                        {{ t(messages, group.titleKey) }}
                                    </p>
                                    <label class="inline-flex cursor-pointer items-center gap-1.5 text-[11px] text-ink-muted">
                                        <input
                                            type="checkbox"
                                            class="ui-check"
                                            :checked="isGroupSelected(group.items)"
                                            @change="toggleGroup(group.items, $event.target.checked)"
                                        >
                                        <span>{{ t(messages, 'selectAllSection') }}</span>
                                    </label>
                                </div>
                                <div class="space-y-1">
                                    <label
                                        v-for="opt in group.items"
                                        :key="opt.id"
                                        class="flex cursor-pointer items-center gap-2 rounded-lg px-1.5 py-1.5 text-sm text-ink hover:bg-surface-muted"
                                    >
                                        <input
                                            type="checkbox"
                                            class="ui-check"
                                            :checked="roleForm.permission_ids.includes(opt.id)"
                                            @change="toggleRolePermission(opt.id)"
                                        >
                                        <span>{{ permissionLabel(opt) }}</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex shrink-0 items-center justify-between gap-2 border-t border-line px-4 py-3">
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
        </aside>
    </Transition>
</template>
