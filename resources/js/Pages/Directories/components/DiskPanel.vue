<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    t: { type: Function, required: true },
    messages: { type: Object, required: true },
});

const loadingFolders = ref(false);
const loadingFiles = ref(false);
const folders = ref([]);
const files = ref([]);
const folderMeta = ref({ current_page: 1, last_page: 1, total: 0 });
const fileMeta = ref({ current_page: 1, last_page: 1, total: 0 });
const folderQuery = ref('');
const fileQuery = ref('');
const includeDeleted = ref(true);
const currentFolder = ref(null);
const parentPath = ref('');
const error = ref('');

let folderSearchTimer = null;
let fileSearchTimer = null;

const isInsideFolder = computed(() => currentFolder.value !== null);
const currentFolderTitle = computed(() => currentFolder.value?.name || currentFolder.value?.folder_name?.split('/').pop() || '');
const breadcrumbSegments = computed(() => {
    const path = parentPath.value.trim();
    if (!path) {
        return [];
    }

    const parts = path.split('/').filter(Boolean);
    return parts.map((name, index) => ({
        name,
        path: parts.slice(0, index + 1).join('/'),
    }));
});

const folderKey = (folder) => `${folder.list_id}:${folder.path || folder.folder_name}:${folder.is_leaf ? '1' : '0'}`;

const encodeFolderRef = (folder) => [
    folder.list_id,
    folder.folder_bitrix_id ?? 0,
    encodeURIComponent(folder.path || folder.folder_name || ''),
].join(':');

const decodeFolderRef = (value) => {
    const raw = String(value ?? '').trim();
    if (!raw) {
        return null;
    }

    const parts = raw.split(':');
    if (parts.length < 3) {
        return null;
    }

    const listId = Number(parts[0]);
    const bitrixId = Number(parts[1]);
    if (!Number.isFinite(listId) || listId < 1) {
        return null;
    }

    let folderName = '';
    try {
        folderName = decodeURIComponent(parts.slice(2).join(':'));
    } catch {
        folderName = parts.slice(2).join(':');
    }

    if (!folderName) {
        return null;
    }

    return {
        list_id: listId,
        folder_bitrix_id: Number.isFinite(bitrixId) && bitrixId > 0 ? bitrixId : null,
        folder_name: folderName,
        path: folderName,
    };
};

const folderSharePath = (folder) => {
    const params = new URLSearchParams({ folder: encodeFolderRef(folder) });

    return `/directories/disk?${params.toString()}`;
};

const setUrlFolderParam = (folder) => {
    if (typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);
    if (!folder) {
        url.searchParams.delete('folder');
    } else {
        url.searchParams.set('folder', encodeFolderRef(folder));
    }

    window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
};

const fetchFolders = async (page = 1) => {
    loadingFolders.value = true;
    error.value = '';

    try {
        const params = {
            page,
            per_page: 96,
        };

        if (parentPath.value) {
            params.parent = parentPath.value;
        }

        if (folderQuery.value.trim()) {
            params.search = folderQuery.value.trim();
        }

        const { data } = await window.axios.get('/api/directories/disk/browser/folders', { params });
        folders.value = data.items ?? [];
        folderMeta.value = {
            current_page: data.meta?.current_page ?? 1,
            last_page: data.meta?.last_page ?? 1,
            total: data.meta?.total ?? 0,
        };
    } catch (e) {
        error.value = e?.response?.data?.message || props.t(props.messages, 'requestError');
        folders.value = [];
    } finally {
        loadingFolders.value = false;
    }
};

const fetchFiles = async (page = 1) => {
    if (!currentFolder.value) {
        files.value = [];
        return;
    }

    loadingFiles.value = true;
    error.value = '';

    try {
        const params = {
            page,
            per_page: 100,
            list_id: currentFolder.value.list_id,
            folder_name: currentFolder.value.folder_name || currentFolder.value.path,
            include_deleted: includeDeleted.value ? 1 : 0,
        };

        if (currentFolder.value.folder_bitrix_id) {
            params.folder_bitrix_id = currentFolder.value.folder_bitrix_id;
        }

        if (fileQuery.value.trim()) {
            params.search = fileQuery.value.trim();
        }

        const { data } = await window.axios.get('/api/directories/disk/browser/files', { params });
        files.value = data.items ?? [];
        fileMeta.value = {
            current_page: data.meta?.current_page ?? 1,
            last_page: data.meta?.last_page ?? 1,
            total: data.meta?.total ?? 0,
        };
    } catch (e) {
        error.value = e?.response?.data?.message || props.t(props.messages, 'requestError');
        files.value = [];
    } finally {
        loadingFiles.value = false;
    }
};

const openDirectory = (folder, { syncUrl = true } = {}) => {
    currentFolder.value = null;
    files.value = [];
    parentPath.value = folder.path || folder.folder_name || '';
    folderQuery.value = '';
    if (syncUrl) {
        setUrlFolderParam({
            list_id: folder.list_id,
            folder_bitrix_id: 0,
            folder_name: parentPath.value,
            path: parentPath.value,
        });
    }
    fetchFolders(1);
};

const openFolder = (folder, { syncUrl = true } = {}) => {
    const fullPath = folder.path || folder.folder_name || '';
    const parts = fullPath.split('/').filter(Boolean);
    const leafName = parts.pop() || fullPath;
    parentPath.value = parts.join('/');
    currentFolder.value = {
        ...folder,
        path: fullPath,
        folder_name: fullPath,
        name: folder.name || leafName,
    };
    fileQuery.value = '';
    includeDeleted.value = true;
    if (syncUrl) {
        setUrlFolderParam(currentFolder.value);
    }
    fetchFiles(1);
};

const openNode = (folder) => {
    if (folder?.is_leaf) {
        openFolder(folder);
        return;
    }

    openDirectory(folder);
};

const goToRoot = () => {
    currentFolder.value = null;
    files.value = [];
    fileQuery.value = '';
    folderQuery.value = '';
    parentPath.value = '';
    error.value = '';
    setUrlFolderParam(null);
    fetchFolders(1);
};

const goToBreadcrumb = (path) => {
    currentFolder.value = null;
    files.value = [];
    parentPath.value = path || '';
    folderQuery.value = '';
    if (!path) {
        setUrlFolderParam(null);
    } else {
        setUrlFolderParam({
            list_id: folders.value[0]?.list_id || 322,
            folder_bitrix_id: 0,
            folder_name: path,
            path,
        });
    }
    fetchFolders(1);
};

const goBackToFolders = () => {
    if (currentFolder.value) {
        const path = currentFolder.value.path || currentFolder.value.folder_name || '';
        const parts = path.split('/').filter(Boolean);
        parts.pop();
        currentFolder.value = null;
        files.value = [];
        fileQuery.value = '';
        error.value = '';
        parentPath.value = parts.join('/');
        if (parentPath.value) {
            setUrlFolderParam({
                list_id: 322,
                folder_bitrix_id: 0,
                folder_name: parentPath.value,
                path: parentPath.value,
            });
        } else {
            setUrlFolderParam(null);
        }
        fetchFolders(1);
        return;
    }

    if (parentPath.value) {
        const parts = parentPath.value.split('/').filter(Boolean);
        parts.pop();
        parentPath.value = parts.join('/');
        if (parentPath.value) {
            setUrlFolderParam({
                list_id: folders.value[0]?.list_id || 322,
                folder_bitrix_id: 0,
                folder_name: parentPath.value,
                path: parentPath.value,
            });
        } else {
            setUrlFolderParam(null);
        }
        fetchFolders(1);
        return;
    }

    goToRoot();
};

const openFolderFromUrl = async () => {
    if (typeof window === 'undefined') {
        return false;
    }

    const ref = decodeFolderRef(new URL(window.location.href).searchParams.get('folder'));
    if (!ref) {
        return false;
    }

    try {
        const params = {
            list_id: ref.list_id,
            folder_name: ref.folder_name,
        };
        if (ref.folder_bitrix_id) {
            params.folder_bitrix_id = ref.folder_bitrix_id;
        }

        const { data } = await window.axios.get('/api/directories/disk/browser/folder', { params });
        if (data?.item?.is_leaf) {
            openFolder(data.item, { syncUrl: true });
            return true;
        }
        if (data?.item) {
            openDirectory(data.item, { syncUrl: true });
            return true;
        }
    } catch {
        openDirectory(ref, { syncUrl: true });
        return true;
    }

    return false;
};

const downloadFile = async (file) => {
    if (!file?.download_url && !file?.id) {
        return;
    }

    try {
        const url = file.download_url || `/api/directories/disk/browser/files/${file.id}/download`;
        const response = await window.axios.get(url, { responseType: 'blob' });
        const blobUrl = window.URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = blobUrl;
        link.download = file.original_name || `file_${file.bitrix_file_id}`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(blobUrl);
    } catch (e) {
        error.value = e?.response?.data?.message || props.t(props.messages, 'requestError');
    }
};

const previewFile = ref(null);

const isImageFile = (file) => Boolean(file?.is_image && file?.preview_url);
const isPdfFile = (file) => Boolean(file?.is_pdf && file?.preview_url);
const canPreviewFile = (file) => Boolean(file?.can_preview && file?.preview_url && file?.exists);

const normalizeFileName = (name) => {
    const raw = String(name ?? '').trim().toLowerCase().replace(/\s+/g, ' ');
    if (!raw) {
        return '';
    }

    return raw.replace(/\s+\(\d+\)(?=\.[^.]+$)/, '');
};

const duplicateNameKeys = computed(() => {
    const counts = new Map();
    for (const file of files.value) {
        const key = normalizeFileName(file?.original_name || `file_${file?.bitrix_file_id}`);
        if (!key) {
            continue;
        }
        counts.set(key, (counts.get(key) || 0) + 1);
    }

    const dupes = new Set();
    for (const [key, count] of counts.entries()) {
        if (count > 1) {
            dupes.add(key);
        }
    }

    return dupes;
});

const isDuplicateFile = (file) => {
    const key = normalizeFileName(file?.original_name || `file_${file?.bitrix_file_id}`);
    return key !== '' && duplicateNameKeys.value.has(key);
};

const openPreview = (file) => {
    if (!canPreviewFile(file)) {
        return;
    }
    previewFile.value = file;
};

const closePreview = () => {
    previewFile.value = null;
};

const onPreviewKeydown = (event) => {
    if (event.key === 'Escape') {
        closePreview();
    }
};

watch(previewFile, (value) => {
    if (typeof window === 'undefined') {
        return;
    }
    if (value) {
        window.addEventListener('keydown', onPreviewKeydown);
        return;
    }
    window.removeEventListener('keydown', onPreviewKeydown);
});

const formatDate = (value) => {
    if (!value) {
        return '—';
    }

    try {
        return new Date(value).toLocaleString();
    } catch {
        return String(value);
    }
};

const refreshAll = () => {
    if (currentFolder.value) {
        fetchFiles(fileMeta.value.current_page);
        return;
    }

    fetchFolders(folderMeta.value.current_page);
};

watch(folderQuery, () => {
    clearTimeout(folderSearchTimer);
    folderSearchTimer = setTimeout(() => fetchFolders(1), 280);
});

watch(fileQuery, () => {
    clearTimeout(fileSearchTimer);
    fileSearchTimer = setTimeout(() => fetchFiles(1), 280);
});

watch(includeDeleted, () => {
    if (currentFolder.value) {
        fetchFiles(1);
    }
});

onMounted(async () => {
    const opened = await openFolderFromUrl();
    if (!opened) {
        fetchFolders(1);
    }
});

onUnmounted(() => {
    if (typeof window !== 'undefined') {
        window.removeEventListener('keydown', onPreviewKeydown);
    }
});
</script>

<template>
    <section class="ui-page lg:!h-full">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0 flex-1">
                <nav class="flex flex-wrap items-center gap-1.5 text-sm">
                    <button
                        type="button"
                        class="ui-section-title !text-lg transition hover:text-brand"
                        @click="goToRoot"
                    >
                        {{ t(messages, 'disk') }}
                    </button>
                    <template v-for="segment in breadcrumbSegments" :key="segment.path">
                        <span class="text-stone-300 dark:text-slate-600" aria-hidden="true">/</span>
                        <button
                            type="button"
                            class="truncate font-medium text-stone-600 transition hover:text-brand dark:text-slate-300 dark:hover:text-brand"
                            @click="goToBreadcrumb(segment.path)"
                        >
                            {{ segment.name }}
                        </button>
                    </template>
                    <template v-if="isInsideFolder">
                        <span class="text-stone-300 dark:text-slate-600" aria-hidden="true">/</span>
                        <span class="truncate font-medium text-stone-700 dark:text-slate-200">
                            {{ currentFolderTitle }}
                        </span>
                    </template>
                </nav>
                <p class="ui-muted mt-1">
                    {{ isInsideFolder ? t(messages, 'diskFilesInFolder') : t(messages, 'diskHint') }}
                </p>
                <div
                    v-if="isInsideFolder && (currentFolder?.folder_url || currentFolder?.list_element_url)"
                    class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1"
                >
                    <a
                        v-if="currentFolder?.list_element_url"
                        :href="currentFolder.list_element_url"
                        target="_blank"
                        rel="noopener"
                        class="ui-link inline-flex items-center gap-1.5 text-sm"
                    >
                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                        </svg>
                        {{ t(messages, 'diskOpenInList') }}
                    </a>
                    <a
                        v-if="currentFolder?.folder_url"
                        :href="currentFolder.folder_url"
                        target="_blank"
                        rel="noopener"
                        class="ui-link inline-flex items-center gap-1.5 text-sm"
                    >
                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H18a.75.75 0 0 1 .75.75V11.5M10.5 13.5 18.75 5.25M6.75 8.25v9a.75.75 0 0 0 .75.75h9" />
                        </svg>
                        {{ t(messages, 'diskOpenInBitrix') }}
                    </a>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button
                    v-if="isInsideFolder || parentPath"
                    type="button"
                    class="ui-btn ui-btn-secondary"
                    @click="goBackToFolders"
                >
                    ← {{ t(messages, 'diskBack') }}
                </button>
                <button
                    type="button"
                    class="ui-btn ui-btn-secondary"
                    @click="refreshAll"
                >
                    {{ t(messages, 'diskRefresh') }}
                </button>
            </div>
        </div>

        <p v-if="error" class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
            {{ error }}
        </p>

        <div v-if="!isInsideFolder" class="flex min-h-0 flex-1 flex-col">
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <input
                    v-model="folderQuery"
                    type="search"
                    class="ui-input max-w-md"
                    :placeholder="t(messages, 'diskSearchFolders')"
                >
                <span class="ui-muted text-xs">
                    {{ folderMeta.total }} {{ t(messages, 'diskFolders') }}
                </span>
            </div>

            <div class="ui-scroll min-h-0 flex-1 overflow-y-auto">
                <p v-if="loadingFolders" class="ui-muted py-8">{{ t(messages, 'loading') }}</p>
                <p v-else-if="folders.length === 0" class="ui-empty">{{ t(messages, 'empty') }}</p>
                <div
                    v-else
                    class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6"
                >
                    <a
                        v-for="folder in folders"
                        :key="folderKey(folder)"
                        :href="folderSharePath(folder)"
                        class="group flex flex-col items-start rounded-xl border border-stone-200 bg-white p-3.5 text-left no-underline transition hover:-translate-y-0.5 hover:border-brand/50 hover:bg-brand-soft/50 hover:shadow-sm dark:border-slate-700 dark:bg-slate-900/60 dark:hover:border-brand/40 dark:hover:bg-brand/10"
                        @click.prevent="openNode(folder)"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 24 24"
                            fill="currentColor"
                            class="h-10 w-10 text-brand transition group-hover:text-brand-hover"
                            aria-hidden="true"
                        >
                            <path d="M2 6.75A2.75 2.75 0 0 1 4.75 4h4.086a1.75 1.75 0 0 1 1.237.513l1.328 1.328A.25.25 0 0 0 11.586 6H19.25A2.75 2.75 0 0 1 22 8.75v8.5A2.75 2.75 0 0 1 19.25 20H4.75A2.75 2.75 0 0 1 2 17.25v-10.5Z" />
                        </svg>
                        <div class="mt-2 w-full truncate text-sm font-semibold text-stone-900 dark:text-slate-100" :title="folder.name || folder.folder_name">
                            {{ folder.name || folder.folder_name }}
                        </div>
                    </a>
                </div>
            </div>

            <div
                v-if="folderMeta.last_page > 1"
                class="mt-4 flex items-center justify-between gap-3 border-t border-stone-200 pt-3 text-sm dark:border-slate-700"
            >
                <button
                    type="button"
                    class="ui-btn ui-btn-secondary"
                    :disabled="folderMeta.current_page <= 1 || loadingFolders"
                    @click="fetchFolders(folderMeta.current_page - 1)"
                >
                    {{ t(messages, 'prev') }}
                </button>
                <span class="text-stone-600 dark:text-slate-300">{{ folderMeta.current_page }} / {{ folderMeta.last_page }}</span>
                <button
                    type="button"
                    class="ui-btn ui-btn-secondary"
                    :disabled="folderMeta.current_page >= folderMeta.last_page || loadingFolders"
                    @click="fetchFolders(folderMeta.current_page + 1)"
                >
                    {{ t(messages, 'next') }}
                </button>
            </div>
        </div>

        <div v-else class="flex min-h-0 flex-1 flex-col">
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <input
                    v-model="fileQuery"
                    type="search"
                    class="ui-input max-w-md"
                    :placeholder="t(messages, 'diskSearchFiles')"
                >
                <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-stone-600 dark:text-slate-300">
                    <input v-model="includeDeleted" type="checkbox" class="ui-check">
                    <span>{{ t(messages, 'diskShowDeleted') }}</span>
                </label>
            </div>

            <div class="ui-scroll min-h-0 flex-1 overflow-auto rounded-xl border border-stone-200/90 dark:border-slate-700/80">
                <p v-if="loadingFiles" class="ui-muted p-6">{{ t(messages, 'loading') }}</p>
                <p v-else-if="files.length === 0" class="ui-empty m-4">{{ t(messages, 'empty') }}</p>
                <table v-else class="min-w-full text-left text-sm leading-snug">
                    <thead class="sticky top-0 bg-stone-50/95 text-xs uppercase tracking-wide text-stone-500 backdrop-blur-sm dark:bg-slate-800/95 dark:text-slate-400">
                        <tr>
                            <th class="px-3 py-2.5 font-semibold">{{ t(messages, 'diskFileName') }}</th>
                            <th class="px-3 py-2.5 font-semibold">{{ t(messages, 'diskField') }}</th>
                            <th class="px-3 py-2.5 font-semibold">{{ t(messages, 'diskStatus') }}</th>
                            <th class="px-3 py-2.5 font-semibold">{{ t(messages, 'diskSyncedAt') }}</th>
                            <th class="px-3 py-2.5 font-semibold" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="file in files"
                            :key="file.id"
                            class="border-t border-stone-100 transition hover:bg-stone-50/80 dark:border-slate-800 dark:hover:bg-white/[0.03]"
                            :class="file.is_deleted ? 'opacity-60' : ''"
                        >
                            <td class="px-3 py-2">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-3 text-left"
                                    :class="canPreviewFile(file) ? 'cursor-pointer' : 'cursor-default'"
                                    :disabled="!canPreviewFile(file)"
                                    @click="openPreview(file)"
                                >
                                    <div
                                        v-if="isImageFile(file)"
                                        class="h-10 w-10 shrink-0 overflow-hidden rounded-lg border border-stone-200 bg-stone-50 dark:border-slate-700 dark:bg-slate-900"
                                    >
                                        <img :src="file.preview_url" :alt="file.original_name" class="h-full w-full object-cover">
                                    </div>
                                    <div
                                        v-else
                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-stone-200 bg-stone-50 text-[10px] font-semibold uppercase text-stone-400 dark:border-slate-700 dark:bg-slate-900"
                                    >
                                        {{ isPdfFile(file) ? 'pdf' : 'file' }}
                                    </div>
                                    <div class="min-w-0">
                                        <div
                                            class="truncate font-medium text-stone-900 dark:text-slate-100"
                                            :class="canPreviewFile(file) ? 'underline-offset-2 hover:text-brand hover:underline' : ''"
                                            :title="file.original_name"
                                        >
                                            {{ file.original_name || `file_${file.bitrix_file_id}` }}
                                        </div>
                                        <div v-if="!file.exists" class="text-xs text-red-600 dark:text-red-400">
                                            {{ t(messages, 'diskMissingFile') }}
                                        </div>
                                    </div>
                                </button>
                            </td>
                            <td class="px-3 py-2.5 text-stone-600 dark:text-slate-300">{{ file.field_code }}</td>
                            <td class="px-3 py-2.5">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span
                                        class="ui-chip"
                                        :class="file.is_deleted
                                            ? 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300'
                                            : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'"
                                    >
                                        {{ file.is_deleted ? t(messages, 'diskDeleted') : t(messages, 'diskActive') }}
                                    </span>
                                    <span
                                        v-if="isDuplicateFile(file)"
                                        class="ui-chip bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300"
                                    >
                                        {{ t(messages, 'diskDuplicate') }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-3 py-2.5 whitespace-nowrap text-stone-600 dark:text-slate-300">
                                {{ formatDate(file.last_synced_at) }}
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <div class="inline-flex items-center gap-2">
                                    <button
                                        v-if="canPreviewFile(file)"
                                        type="button"
                                        class="ui-btn ui-btn-secondary"
                                        @click="openPreview(file)"
                                    >
                                        {{ t(messages, 'diskPreview') }}
                                    </button>
                                    <button
                                        v-if="file.exists"
                                        type="button"
                                        class="ui-btn ui-btn-secondary"
                                        @click="downloadFile(file)"
                                    >
                                        {{ t(messages, 'diskDownload') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="fileMeta.last_page > 1"
                class="mt-4 flex items-center justify-between gap-3 border-t border-stone-200 pt-3 text-sm dark:border-slate-700"
            >
                <button
                    type="button"
                    class="ui-btn ui-btn-secondary"
                    :disabled="fileMeta.current_page <= 1 || loadingFiles"
                    @click="fetchFiles(fileMeta.current_page - 1)"
                >
                    {{ t(messages, 'prev') }}
                </button>
                <span class="text-stone-600 dark:text-slate-300">{{ fileMeta.current_page }} / {{ fileMeta.last_page }} · {{ fileMeta.total }} {{ t(messages, 'totalRows') }}</span>
                <button
                    type="button"
                    class="ui-btn ui-btn-secondary"
                    :disabled="fileMeta.current_page >= fileMeta.last_page || loadingFiles"
                    @click="fetchFiles(fileMeta.current_page + 1)"
                >
                    {{ t(messages, 'next') }}
                </button>
            </div>
        </div>

        <Teleport to="body">
            <div
                v-if="previewFile"
                class="ui-overlay z-50 flex items-center justify-center p-4"
                role="dialog"
                aria-modal="true"
                @click.self="closePreview"
            >
                <div class="flex max-h-[92vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900">
                    <div class="flex items-center justify-between gap-3 border-b border-stone-200 px-4 py-3 dark:border-slate-700">
                        <div class="min-w-0 truncate text-sm font-semibold text-stone-900 dark:text-slate-100" :title="previewFile.original_name">
                            {{ previewFile.original_name || `file_${previewFile.bitrix_file_id}` }}
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <button
                                type="button"
                                class="ui-btn ui-btn-secondary"
                                @click="downloadFile(previewFile)"
                            >
                                {{ t(messages, 'diskDownload') }}
                            </button>
                            <button
                                type="button"
                                class="ui-btn ui-btn-secondary"
                                @click="closePreview"
                            >
                                {{ t(messages, 'diskClosePreview') }}
                            </button>
                        </div>
                    </div>
                    <div class="flex min-h-0 flex-1 items-center justify-center bg-stone-100 p-3 dark:bg-slate-950">
                        <img
                            v-if="isImageFile(previewFile)"
                            :src="previewFile.preview_url"
                            :alt="previewFile.original_name"
                            class="max-h-[80vh] max-w-full object-contain"
                        >
                        <iframe
                            v-else-if="isPdfFile(previewFile)"
                            :src="previewFile.preview_url"
                            class="h-[80vh] w-full rounded-md border border-stone-200 bg-white dark:border-slate-700"
                            title="PDF preview"
                        />
                    </div>
                </div>
            </div>
        </Teleport>
    </section>
</template>
