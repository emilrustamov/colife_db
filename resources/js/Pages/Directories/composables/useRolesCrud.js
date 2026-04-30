import { ref } from 'vue';

export const useRolesCrud = ({
    canManageRoles,
    rowPrimaryKey,
    fetchRows,
    t,
    messages,
}) => {
    const roleModalOpen = ref(false);
    const editingRoleId = ref(null);
    const roleForm = ref({ name: '', permission_ids: [] });
    const permissionOptions = ref([]);
    const roleAdminError = ref('');
    const roleAdminSaving = ref(false);

    const resetRoleForm = () => {
        editingRoleId.value = null;
        roleForm.value = { name: '', permission_ids: [] };
        roleAdminError.value = '';
    };

    const loadPermissionOptions = async () => {
        if (!canManageRoles.value) {
            return;
        }

        try {
            const { data } = await window.axios.get('/api/admin/roles');
            permissionOptions.value = data.permissions ?? [];
        } catch {
            permissionOptions.value = [];
        }
    };

    const openCreateRoleModal = async () => {
        resetRoleForm();
        await loadPermissionOptions();
        roleModalOpen.value = true;
    };

    const toggleRolePermission = (permissionId) => {
        const ids = [...roleForm.value.permission_ids];
        const idx = ids.indexOf(permissionId);
        if (idx === -1) {
            ids.push(permissionId);
        } else {
            ids.splice(idx, 1);
        }
        roleForm.value.permission_ids = ids;
    };

    const openEditRoleModal = async (row) => {
        if (!canManageRoles.value) {
            return;
        }

        roleAdminError.value = '';

        try {
            const { data } = await window.axios.get('/api/admin/roles');
            const roles = data.roles ?? [];
            permissionOptions.value = data.permissions ?? [];
            const role = roles.find((r) => String(r.id) === String(rowPrimaryKey(row)));
            if (!role) {
                return;
            }

            editingRoleId.value = role.id;
            roleForm.value = {
                name: role.name ?? '',
                permission_ids: Array.isArray(role.permissions) ? role.permissions.map((p) => p.id) : [],
            };
            roleModalOpen.value = true;
        } catch {
            roleAdminError.value = t(messages, 'requestError');
        }
    };

    const closeRoleModal = () => {
        roleModalOpen.value = false;
        resetRoleForm();
    };

    const submitRole = async () => {
        roleAdminSaving.value = true;
        roleAdminError.value = '';

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
            await fetchRows();
        } catch (e) {
            const msg = e.response?.data?.message;
            const first = e.response?.data?.errors ? Object.values(e.response.data.errors).flat()[0] : null;
            roleAdminError.value = first || msg || t(messages, 'requestError');
        } finally {
            roleAdminSaving.value = false;
        }
    };

    const deleteRoleFromModal = async () => {
        if (!editingRoleId.value || roleForm.value.name === 'admin') {
            return;
        }

        if (!window.confirm(roleForm.value.name || String(editingRoleId.value))) {
            return;
        }

        roleAdminSaving.value = true;
        roleAdminError.value = '';

        try {
            await window.axios.delete(`/api/admin/roles/${editingRoleId.value}`);
            closeRoleModal();
            await fetchRows();
        } catch {
            roleAdminError.value = t(messages, 'requestError');
        } finally {
            roleAdminSaving.value = false;
        }
    };

    return {
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
    };
};
