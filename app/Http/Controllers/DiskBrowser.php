<?php

namespace App\Http\Controllers;

use App\Models\DiskSyncedFile;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DiskBrowser extends Controller
{
    /**
     * List synced folder tree nodes for the current parent path.
     */
    public function folders(Request $request): JsonResponse
    {
        $this->authorizeDiskAccess($request->user());

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'list_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'parent' => ['sometimes', 'nullable', 'string', 'max:1024'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 50);
        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        $listId = isset($validated['list_id']) ? (int) $validated['list_id'] : null;
        $parent = $this->normalizeFolderPath((string) ($validated['parent'] ?? ''));

        $query = DiskSyncedFile::query()
            ->selectRaw('
                list_id,
                folder_bitrix_id,
                folder_name,
                MAX(folder_url) as folder_url,
                MAX(element_bitrix_id) as element_bitrix_id,
                MAX(last_synced_at) as last_synced_at
            ')
            ->whereNotNull('folder_name')
            ->where('folder_name', '!=', '')
            ->groupBy('list_id', 'folder_bitrix_id', 'folder_name')
            ->orderBy('folder_name');

        if ($listId !== null && $listId > 0) {
            $query->where('list_id', $listId);
        }

        if ($parent !== '') {
            $query->where(function ($q) use ($parent): void {
                $q->where('folder_name', $parent)
                    ->orWhere('folder_name', 'like', $parent.'/%');
            });
        }

        if ($search !== '') {
            $needle = '%'.addcslashes($search, '%_\\').'%';
            $query->where('folder_name', 'like', $needle);
        }

        $leaves = $query->get();
        $nodes = [];

        foreach ($leaves as $row) {
            $path = $this->normalizeFolderPath((string) $row->folder_name);
            if ($path === '') {
                continue;
            }

            if ($parent === '') {
                $relative = $path;
            } elseif ($path === $parent) {
                continue;
            } elseif (str_starts_with($path, $parent.'/')) {
                $relative = substr($path, strlen($parent) + 1);
            } else {
                continue;
            }

            $parts = array_values(array_filter(explode('/', $relative), static fn (string $part): bool => $part !== ''));
            if ($parts === []) {
                continue;
            }

            $childName = $parts[0];
            $childPath = $parent === '' ? $childName : $parent.'/'.$childName;
            $isExactLeaf = count($parts) === 1;

            if (! isset($nodes[$childPath])) {
                $nodes[$childPath] = [
                    'list_id' => (int) $row->list_id,
                    'name' => $childName,
                    'path' => $childPath,
                    'folder_name' => $childPath,
                    'is_leaf' => $isExactLeaf,
                    'folder_bitrix_id' => $isExactLeaf && $row->folder_bitrix_id !== null ? (int) $row->folder_bitrix_id : null,
                    'folder_url' => $isExactLeaf && $row->folder_url !== null && $row->folder_url !== ''
                        ? (string) $row->folder_url
                        : null,
                    'element_bitrix_id' => $isExactLeaf && $row->element_bitrix_id !== null
                        ? (int) $row->element_bitrix_id
                        : null,
                    'last_synced_at' => $row->last_synced_at,
                ];
            }

            if (! $isExactLeaf) {
                $nodes[$childPath]['is_leaf'] = false;
                $nodes[$childPath]['folder_bitrix_id'] = null;
                $nodes[$childPath]['folder_url'] = null;
                $nodes[$childPath]['element_bitrix_id'] = null;
            } elseif ($nodes[$childPath]['is_leaf']) {
                if ($nodes[$childPath]['folder_url'] === null && $row->folder_url) {
                    $nodes[$childPath]['folder_url'] = (string) $row->folder_url;
                }
                if ($nodes[$childPath]['folder_bitrix_id'] === null && $row->folder_bitrix_id !== null) {
                    $nodes[$childPath]['folder_bitrix_id'] = (int) $row->folder_bitrix_id;
                }
                if ($nodes[$childPath]['element_bitrix_id'] === null && $row->element_bitrix_id !== null) {
                    $nodes[$childPath]['element_bitrix_id'] = (int) $row->element_bitrix_id;
                }
            }

            if ($row->last_synced_at !== null
                && ($nodes[$childPath]['last_synced_at'] === null || $row->last_synced_at > $nodes[$childPath]['last_synced_at'])
            ) {
                $nodes[$childPath]['last_synced_at'] = $row->last_synced_at;
            }
        }

        $items = collect(array_values($nodes))
            ->sortBy(fn (array $node): string => mb_strtolower((string) $node['name']), SORT_NATURAL)
            ->values()
            ->map(function (array $node): array {
                $node['list_element_url'] = $node['is_leaf']
                    ? $this->listElementUrl((int) $node['list_id'], $node['element_bitrix_id'] ?? null)
                    : null;

                return $node;
            });

        $total = $items->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $pageItems = $items->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return response()->json([
            'success' => true,
            'parent' => $parent,
            'items' => $pageItems,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Resolve a synced folder path (leaf) or navigation node.
     */
    public function folder(Request $request): JsonResponse
    {
        $this->authorizeDiskAccess($request->user());

        $validated = $request->validate([
            'list_id' => ['required', 'integer', 'min:1'],
            'folder_name' => ['required', 'string', 'max:1024'],
            'folder_bitrix_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $path = $this->normalizeFolderPath((string) $validated['folder_name']);
        $listId = (int) $validated['list_id'];

        $query = DiskSyncedFile::query()
            ->selectRaw('
                list_id,
                folder_bitrix_id,
                folder_name,
                MAX(folder_url) as folder_url,
                MAX(element_bitrix_id) as element_bitrix_id,
                MAX(last_synced_at) as last_synced_at
            ')
            ->where('list_id', $listId)
            ->where('folder_name', $path)
            ->whereNotNull('folder_name')
            ->where('folder_name', '!=', '')
            ->groupBy('list_id', 'folder_bitrix_id', 'folder_name');

        if (isset($validated['folder_bitrix_id']) && $validated['folder_bitrix_id'] !== null) {
            $query->where('folder_bitrix_id', (int) $validated['folder_bitrix_id']);
        }

        $row = $query->first();
        if ($row !== null) {
            $item = $this->mapFolderRow($row);
            $item['is_leaf'] = true;
            $item['path'] = $path;
            $item['name'] = basename(str_replace('\\', '/', $path));

            return response()->json([
                'success' => true,
                'item' => $item,
            ]);
        }

        $hasChildren = DiskSyncedFile::query()
            ->where('list_id', $listId)
            ->where('folder_name', 'like', $path.'/%')
            ->exists();

        abort_unless($hasChildren, 404);

        return response()->json([
            'success' => true,
            'item' => [
                'list_id' => $listId,
                'folder_bitrix_id' => null,
                'folder_name' => $path,
                'folder_url' => null,
                'element_bitrix_id' => null,
                'list_element_url' => null,
                'last_synced_at' => null,
                'is_leaf' => false,
                'path' => $path,
                'name' => basename(str_replace('\\', '/', $path)),
            ],
        ]);
    }

    /**
     * List files for a synced folder.
     */
    public function files(Request $request): JsonResponse
    {
        $this->authorizeDiskAccess($request->user());

        $validated = $request->validate([
            'list_id' => ['required', 'integer', 'min:1'],
            'folder_name' => ['required', 'string', 'max:1024'],
            'folder_bitrix_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'include_deleted' => ['sometimes', 'boolean'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 100);
        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        $includeDeleted = (bool) ($validated['include_deleted'] ?? true);
        $folderName = $this->normalizeFolderPath((string) $validated['folder_name']);

        $query = DiskSyncedFile::query()
            ->where('list_id', (int) $validated['list_id'])
            ->where('folder_name', $folderName)
            ->orderBy('is_deleted')
            ->orderBy('field_code')
            ->orderBy('original_name');

        if (isset($validated['folder_bitrix_id']) && $validated['folder_bitrix_id'] !== null) {
            $query->where('folder_bitrix_id', (int) $validated['folder_bitrix_id']);
        }

        if (! $includeDeleted) {
            $query->where('is_deleted', false);
        }

        if ($search !== '') {
            $needle = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($q) use ($needle): void {
                $q->where('original_name', 'like', $needle)
                    ->orWhere('field_code', 'like', $needle)
                    ->orWhere('bitrix_file_id', 'like', $needle);
            });
        }

        $paginator = $query->paginate(perPage: $perPage, page: $page);

        $items = collect($paginator->items())->map(function (DiskSyncedFile $row): array {
            $exists = Storage::disk('local')->exists($row->local_path);
            $originalName = (string) ($row->original_name ?? '');
            $nameForType = $originalName !== '' ? $originalName : (string) $row->local_path;
            $isImage = $exists && $this->isImageName($nameForType);
            $isPdf = $exists && $this->isPdfName($nameForType);
            $canPreview = $isImage || $isPdf;

            return [
                'id' => (int) $row->id,
                'list_id' => (int) $row->list_id,
                'element_bitrix_id' => (int) $row->element_bitrix_id,
                'folder_bitrix_id' => $row->folder_bitrix_id !== null ? (int) $row->folder_bitrix_id : null,
                'folder_name' => (string) $row->folder_name,
                'field_code' => (string) $row->field_code,
                'bitrix_file_id' => (int) $row->bitrix_file_id,
                'content_version' => (int) $row->content_version,
                'original_name' => $originalName,
                'is_deleted' => (bool) $row->is_deleted,
                'last_synced_at' => $row->last_synced_at?->toIso8601String(),
                'exists' => $exists,
                'is_image' => $isImage,
                'is_pdf' => $isPdf,
                'can_preview' => $canPreview,
                'preview_url' => $canPreview
                    ? '/api/directories/disk/browser/files/'.$row->id.'/preview'
                    : null,
                'download_url' => '/api/directories/disk/browser/files/'.$row->id.'/download',
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'items' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => max(1, $paginator->lastPage()),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Download a synced local file.
     */
    public function download(Request $request, int $id): StreamedResponse
    {
        $this->authorizeDiskAccess($request->user());

        $file = DiskSyncedFile::query()->findOrFail($id);

        abort_unless(Storage::disk('local')->exists($file->local_path), 404);

        $downloadName = $file->original_name !== null && $file->original_name !== ''
            ? $file->original_name
            : basename($file->local_path);

        return Storage::disk('local')->download($file->local_path, $downloadName);
    }

    /**
     * Inline preview for image and PDF files.
     */
    public function preview(Request $request, int $id): StreamedResponse
    {
        $this->authorizeDiskAccess($request->user());

        $file = DiskSyncedFile::query()->findOrFail($id);
        abort_unless(Storage::disk('local')->exists($file->local_path), 404);

        $name = $file->original_name !== null && $file->original_name !== ''
            ? $file->original_name
            : basename($file->local_path);
        abort_unless($this->isPreviewableName($name), 404);

        return Storage::disk('local')->response(
            $file->local_path,
            $name,
            [
                'Content-Type' => $this->mimeFromName($name),
                'Cache-Control' => 'private, max-age=3600',
            ]
        );
    }

    /**
     * Ensure the user may view the disk directory.
     */
    private function authorizeDiskAccess(?Authenticatable $user): void
    {
        abort_if($user === null, 403);

        if ($user->can('directories.view') || $user->can('directory.disk')) {
            return;
        }

        abort(403);
    }

    /**
     * @param  object{
     *     list_id: mixed,
     *     folder_bitrix_id: mixed,
     *     folder_name: mixed,
     *     folder_url: mixed,
     *     element_bitrix_id: mixed,
     *     last_synced_at: mixed
     * }  $row
     * @return array{
     *     list_id: int,
     *     folder_bitrix_id: ?int,
     *     folder_name: string,
     *     folder_url: ?string,
     *     element_bitrix_id: ?int,
     *     list_element_url: ?string,
     *     last_synced_at: mixed
     * }
     */
    private function mapFolderRow(object $row): array
    {
        $listId = (int) $row->list_id;
        $elementId = $row->element_bitrix_id !== null ? (int) $row->element_bitrix_id : null;

        return [
            'list_id' => $listId,
            'folder_bitrix_id' => $row->folder_bitrix_id !== null ? (int) $row->folder_bitrix_id : null,
            'folder_name' => (string) $row->folder_name,
            'folder_url' => $row->folder_url !== null && $row->folder_url !== '' ? (string) $row->folder_url : null,
            'element_bitrix_id' => $elementId !== null && $elementId > 0 ? $elementId : null,
            'list_element_url' => $this->listElementUrl($listId, $elementId),
            'last_synced_at' => $row->last_synced_at,
            'is_leaf' => true,
            'path' => (string) $row->folder_name,
            'name' => basename(str_replace('\\', '/', (string) $row->folder_name)),
        ];
    }

    /**
     * Normalize nested folder path.
     */
    private function normalizeFolderPath(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', trim($path))) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * Build Bitrix universal list element URL.
     */
    private function listElementUrl(int $listId, ?int $elementId): ?string
    {
        if ($listId <= 0 || $elementId === null || $elementId <= 0) {
            return null;
        }

        $domain = trim((string) config('services.bitrix.portal_domain', ''));
        if ($domain === '') {
            return null;
        }

        $domain = rtrim($domain, '/');
        if (! str_starts_with($domain, 'http://') && ! str_starts_with($domain, 'https://')) {
            $domain = 'https://'.$domain;
        }

        return $domain.'/company/lists/'.$listId.'/element/0/'.$elementId.'/?list_section_id=';
    }

    private function isImageName(string $name): bool
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
    }

    private function isPdfName(string $name): bool
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'pdf';
    }

    private function isPreviewableName(string $name): bool
    {
        return $this->isImageName($name) || $this->isPdfName($name);
    }

    private function mimeFromName(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
