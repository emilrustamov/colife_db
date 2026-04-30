import { computed, ref } from 'vue';

export const useDirectoryData = ({ selectedKey, menu, perPage }) => {
    const selectedId = ref(null);
    const loading = ref(false);
    const rowDetailLoading = ref(false);
    const cardModalOpen = ref(false);
    const cardModalTab = ref('form');
    const query = ref('');
    const rows = ref([]);
    const fields = ref([]);
    const form = ref({});
    const timeline = ref([]);
    const page = ref(1);
    const sortField = ref(null);
    const sortDir = ref('desc');
    const paginationMeta = ref({
        current_page: 1,
        last_page: 1,
        per_page: perPage,
        total: 0,
        from: null,
        to: null,
    });
    const directoryDetail = ref(null);
    const orderedFields = ref([]);

    const current = computed(() => menu.value.find((item) => item.key === selectedKey.value) ?? null);

    const rowPrimaryKey = (row) => {
        const idName = directoryDetail.value?.id ?? 'id';
        const key = row[idName];
        return key !== undefined && key !== null ? key : row.id;
    };

    const fetchRows = async () => {
        if (!current.value) {
            return;
        }

        loading.value = true;

        try {
            const params = {
                page: page.value,
                per_page: perPage,
                direction: sortDir.value,
            };

            if (sortField.value) {
                params.sort = sortField.value;
            }

            if (query.value.trim()) {
                params.search = query.value.trim();
            }

            const { data } = await window.axios.get(`/api/directories/${current.value.key}`, { params });
            rows.value = data.rows ?? [];
            fields.value = data.fields ?? [];
            directoryDetail.value = data.directory ?? null;
            paginationMeta.value = {
                ...paginationMeta.value,
                ...(data.meta ?? {}),
            };
        } finally {
            loading.value = false;
        }
    };

    const closeCardModal = () => {
        cardModalOpen.value = false;
        rowDetailLoading.value = false;
        cardModalTab.value = 'form';
        selectedId.value = null;
        form.value = {};
        timeline.value = [];
    };

    const loadList = async (skipSearchRefetch) => {
        cardModalOpen.value = false;
        rowDetailLoading.value = false;
        cardModalTab.value = 'form';

        if (!current.value) {
            selectedId.value = null;
            query.value = '';
            form.value = {};
            timeline.value = [];
            rows.value = [];
            fields.value = [];
            orderedFields.value = [];
            directoryDetail.value = null;
            paginationMeta.value = {
                current_page: 1,
                last_page: 1,
                per_page: perPage,
                total: 0,
                from: null,
                to: null,
            };
            return;
        }

        selectedId.value = null;
        skipSearchRefetch.value = true;
        query.value = '';
        form.value = {};
        timeline.value = [];
        page.value = 1;
        sortField.value = null;
        sortDir.value = 'desc';
        rows.value = [];
        fields.value = [];
        orderedFields.value = [];

        await fetchRows();
        skipSearchRefetch.value = false;
    };

    const toggleSort = (field) => {
        if (sortField.value === field) {
            sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
        } else {
            sortField.value = field;
            sortDir.value = 'asc';
        }

        page.value = 1;
        fetchRows();
    };

    const goPage = (p) => {
        const last = paginationMeta.value.last_page ?? 1;
        const next = Math.min(Math.max(1, p), last);
        page.value = next;
        fetchRows();
    };

    const selectRow = async (row) => {
        const rowId = typeof row === 'object' && row !== null ? rowPrimaryKey(row) : row;

        if (!current.value || rowId === undefined || rowId === null || rowId === '') {
            return;
        }

        selectedId.value = rowId;
        cardModalTab.value = 'form';
        cardModalOpen.value = true;
        rowDetailLoading.value = true;
        form.value = {};
        timeline.value = [];

        try {
            const { data } = await window.axios.get(`/api/directories/${current.value.key}/${rowId}`);
            form.value = { ...(data.row ?? {}) };
            timeline.value = data.timeline ?? [];
        } catch {
            closeCardModal();
        } finally {
            rowDetailLoading.value = false;
        }
    };

    const markRowSelected = (row) => {
        const rowId = typeof row === 'object' && row !== null ? rowPrimaryKey(row) : row;

        if (rowId === undefined || rowId === null || rowId === '') {
            return;
        }

        selectedId.value = rowId;
    };

    const renderValue = (value) => {
        if (value === null || value === undefined) {
            return '';
        }

        if (typeof value === 'object') {
            return JSON.stringify(value, null, 0);
        }

        return String(value);
    };

    return {
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
    };
};
