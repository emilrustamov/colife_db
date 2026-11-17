import { ref } from 'vue';

export const useUsersCrud = ({
    canManageUsers,
    canEditUsers,
    canDeleteUsers,
    currentUserId,
    rowPrimaryKey,
    fetchRows,
    t,
    messages,
}) => {
    const userModalOpen = ref(false);
    const editingUserId = ref(null);
    const userForm = ref({ name: '', email: '', password: '', role_id: null });
    const roleOptions = ref([]);
    const userAdminError = ref('');
    const userAdminSaving = ref(false);

    const canAccessUsersAdmin = () =>
        canManageUsers.value || canEditUsers.value || canDeleteUsers.value;

    const resetUserForm = () => {
        editingUserId.value = null;
        userForm.value = { name: '', email: '', password: '', role_id: null };
        userAdminError.value = '';
    };

    const loadUserRoleOptions = async () => {
        if (!canAccessUsersAdmin()) {
            return;
        }

        try {
            const { data } = await window.axios.get('/api/admin/users');
            roleOptions.value = data.roles ?? [];
        } catch {
            roleOptions.value = [];
        }
    };

    const openCreateUserModal = async () => {
        if (!canManageUsers.value) {
            return;
        }

        resetUserForm();
        await loadUserRoleOptions();
        userModalOpen.value = true;
    };

    const setUserRole = (roleId) => {
        userForm.value.role_id = roleId;
    };

    const openEditUserModal = async (row) => {
        if (!canEditUsers.value) {
            return;
        }

        userAdminError.value = '';

        try {
            const { data } = await window.axios.get('/api/admin/users');
            const users = data.users ?? [];
            roleOptions.value = data.roles ?? [];
            const user = users.find((u) => String(u.id) === String(rowPrimaryKey(row)));
            if (!user) {
                return;
            }

            editingUserId.value = user.id;
            userForm.value = {
                name: user.name ?? '',
                email: user.email ?? '',
                password: '',
                role_id: Array.isArray(user.roles) && user.roles.length > 0 ? user.roles[0].id : null,
            };
            userModalOpen.value = true;
        } catch {
            userAdminError.value = t(messages, 'requestError');
        }
    };

    const closeUserModal = () => {
        userModalOpen.value = false;
        resetUserForm();
    };

    const submitUser = async () => {
        if (editingUserId.value && !canEditUsers.value) {
            return;
        }

        if (!editingUserId.value && !canManageUsers.value) {
            return;
        }

        userAdminSaving.value = true;
        userAdminError.value = '';

        try {
            if (editingUserId.value) {
                const payload = {
                    name: userForm.value.name,
                    email: userForm.value.email,
                    role_id: userForm.value.role_id,
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
                    role_id: userForm.value.role_id,
                });
            }

            closeUserModal();
            await fetchRows();
        } catch (e) {
            const msg = e.response?.data?.message;
            const first = e.response?.data?.errors ? Object.values(e.response.data.errors).flat()[0] : null;
            userAdminError.value = first || msg || t(messages, 'requestError');
        } finally {
            userAdminSaving.value = false;
        }
    };

    const deleteUserFromModal = async () => {
        if (!editingUserId.value || editingUserId.value === currentUserId.value || !canDeleteUsers.value) {
            return;
        }

        if (!window.confirm(userForm.value.email || String(editingUserId.value))) {
            return;
        }

        userAdminSaving.value = true;
        userAdminError.value = '';

        try {
            await window.axios.delete(`/api/admin/users/${editingUserId.value}`);
            closeUserModal();
            await fetchRows();
        } catch {
            userAdminError.value = t(messages, 'requestError');
        } finally {
            userAdminSaving.value = false;
        }
    };

    return {
        userModalOpen,
        editingUserId,
        userForm,
        roleOptions,
        userAdminError,
        userAdminSaving,
        openCreateUserModal,
        openEditUserModal,
        closeUserModal,
        setUserRole,
        submitUser,
        deleteUserFromModal,
    };
};
