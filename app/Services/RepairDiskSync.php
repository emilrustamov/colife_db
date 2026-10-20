<?php

namespace App\Services;

use App\Models\DiskSyncedFile;
use App\Services\Disk\LocalStorage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class RepairDiskSync
{
    public const LIST_ID = 144;

    public function __construct(
        private readonly BitrixWebhook $webhookClient,
        private readonly LocalStorage $localStorage,
        private readonly EnsureApartmentRepairFolders $apartments,
    ) {}

    /**
     * Download new files from Repair/Photo, Estimate, Invoices. Never delete local copies.
     *
     * @return array{
     *     apartments: int,
     *     processed: int,
     *     skipped: int,
     *     downloaded: int,
     *     unchanged: int,
     *     failed: int,
     *     failed_ids: list<int>
     * }
     */
    public function sync(?int $apartmentId = null): array
    {
        $logger = Log::channel('bitrix_disk');
        $items = $this->apartments->listApartments();
        if ($apartmentId !== null) {
            $items = array_values(array_filter(
                $items,
                fn (array $item): bool => (int) ($item['id'] ?? 0) === $apartmentId
            ));
        }

        $stats = [
            'apartments' => count($items),
            'processed' => 0,
            'skipped' => 0,
            'downloaded' => 0,
            'unchanged' => 0,
            'failed' => 0,
            'failed_ids' => [],
        ];

        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            try {
                $result = $this->syncApartment($item);
                if ($result['skipped']) {
                    $stats['skipped']++;
                } else {
                    $stats['processed']++;
                }
                $stats['downloaded'] += $result['downloaded'];
                $stats['unchanged'] += $result['unchanged'];
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['failed_ids'][] = $id;
                $logger->error('Repair disk sync apartment failed', [
                    'apartment_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $logger->info('Repair disk sync completed', $stats);

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{skipped: bool, downloaded: int, unchanged: int}
     */
    private function syncApartment(array $item): array
    {
        $apartmentId = (int) ($item['id'] ?? 0);
        $title = trim((string) ($item['title'] ?? ''));
        $repairFolderId = (int) ($item[EnsureApartmentRepairFolders::FIELD_REPAIR_FOLDER_ID] ?? 0);
        $stageId = (string) ($item['stageId'] ?? '');

        if ($apartmentId <= 0 || $repairFolderId <= 0) {
            return ['skipped' => true, 'downloaded' => 0, 'unchanged' => 0];
        }

        if (in_array($stageId, LandlordContactsSync::EXCLUDED_STAGE_IDS, true)) {
            return ['skipped' => true, 'downloaded' => 0, 'unchanged' => 0];
        }

        $repairChildren = $this->folderChildren($repairFolderId);
        $downloaded = 0;
        $unchanged = 0;

        foreach (EnsureApartmentRepairFolders::CHILD_NAMES as $name) {
            $childId = $this->findFolderId($repairChildren, $name);
            if ($childId === null) {
                continue;
            }

            $folderUrl = $this->folderDetailUrl($childId);
            $folderPath = $this->localStorage->sanitizeFolderPath(
                ($title !== '' ? $title : 'apartment_'.$apartmentId).'/Repair/'.$name
            );
            $folderRelative = $this->localStorage->ensureFolder(self::LIST_ID, $folderPath);
            $files = $this->folderFiles($childId);

            foreach ($files as $file) {
                $fileId = (int) ($file['ID'] ?? 0);
                $originalName = trim((string) ($file['NAME'] ?? ''));
                if ($fileId <= 0) {
                    continue;
                }

                $existing = DiskSyncedFile::query()
                    ->where('list_id', self::LIST_ID)
                    ->where('element_bitrix_id', $apartmentId)
                    ->where('field_code', $name)
                    ->where('bitrix_file_id', $fileId)
                    ->first();

                if (
                    $existing !== null
                    && $existing->local_path !== ''
                    && Storage::disk('local')->exists($existing->local_path)
                ) {
                    $existing->forceFill([
                        'folder_bitrix_id' => $childId,
                        'folder_name' => $folderPath,
                        'folder_url' => $folderUrl,
                        'last_synced_at' => now(),
                    ])->save();
                    $unchanged++;

                    continue;
                }

                $downloadUrl = $this->fileDownloadUrl($file);
                $payloadName = $originalName !== '' ? $originalName : ($name.'_'.$fileId);
                $targetPath = $this->localStorage->activeRelativePath(
                    $folderRelative,
                    $name,
                    $fileId,
                    $payloadName
                );
                $this->localStorage->downloadTo($downloadUrl, $targetPath);

                DiskSyncedFile::query()->updateOrCreate(
                    [
                        'list_id' => self::LIST_ID,
                        'element_bitrix_id' => $apartmentId,
                        'field_code' => $name,
                        'bitrix_file_id' => $fileId,
                    ],
                    [
                        'folder_bitrix_id' => $childId,
                        'folder_name' => $folderPath,
                        'folder_url' => $folderUrl,
                        'content_version' => (int) ($file['SIZE'] ?? 0),
                        'original_name' => $payloadName,
                        'local_path' => $targetPath,
                        'is_deleted' => false,
                        'last_synced_at' => now(),
                    ]
                );
                $downloaded++;
            }
        }

        return ['skipped' => false, 'downloaded' => $downloaded, 'unchanged' => $unchanged];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function folderChildren(int $folderId): array
    {
        $items = [];
        $start = 0;

        do {
            $response = $this->webhookClient->callRaw('disk.folder.getchildren', [
                'id' => $folderId,
                'start' => $start,
            ], $this->webhookClient->diskWebhook());

            $this->assertOk($response, 'disk.folder.getchildren');

            $page = data_get($response, 'result', []);
            if (! is_array($page) || $page === []) {
                break;
            }

            foreach ($page as $row) {
                if (is_array($row)) {
                    $items[] = $row;
                }
            }

            $next = data_get($response, 'next');
            $start = is_numeric($next) ? (int) $next : null;
        } while ($start !== null);

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return list<array<string, mixed>>
     */
    private function folderFiles(int $folderId): array
    {
        $files = [];
        foreach ($this->folderChildren($folderId) as $child) {
            if (strtolower((string) ($child['TYPE'] ?? '')) !== 'file') {
                continue;
            }

            $files[] = $child;
        }

        return $files;
    }

    /**
     * @param  list<array<string, mixed>>  $children
     */
    private function findFolderId(array $children, string $name): ?int
    {
        foreach ($children as $child) {
            if (strtolower((string) ($child['TYPE'] ?? '')) !== 'folder') {
                continue;
            }

            if (strcasecmp((string) ($child['NAME'] ?? ''), $name) !== 0) {
                continue;
            }

            $id = (int) ($child['ID'] ?? 0);

            return $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $file
     */
    private function fileDownloadUrl(array $file): string
    {
        $url = trim((string) ($file['DOWNLOAD_URL'] ?? ''));
        if ($url !== '') {
            return $url;
        }

        $id = (int) ($file['ID'] ?? 0);
        $response = $this->webhookClient->callRaw('disk.file.get', [
            'id' => $id,
        ], $this->webhookClient->diskWebhook());

        $this->assertOk($response, 'disk.file.get');

        $url = trim((string) data_get($response, 'result.DOWNLOAD_URL', ''));
        if ($url === '') {
            throw new RuntimeException('disk.file.get returned empty DOWNLOAD_URL for '.$id);
        }

        return $url;
    }

    private function folderDetailUrl(int $folderId): ?string
    {
        $response = $this->webhookClient->callRaw('disk.folder.get', [
            'id' => $folderId,
        ], $this->webhookClient->diskWebhook());

        $url = trim((string) data_get($response, 'result.DETAIL_URL', ''));

        return $url !== '' ? $url : null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function assertOk(array $response, string $method): void
    {
        $error = data_get($response, 'error');
        if (is_string($error) && $error !== '') {
            throw new RuntimeException($method.': '.(string) (data_get($response, 'error_description') ?: $error));
        }
    }
}
