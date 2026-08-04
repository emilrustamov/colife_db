<?php

namespace App\Services;

use App\Models\DiskSyncedFile;
use App\Services\Disk\FileResolver;
use App\Services\Disk\LocalStorage;
use App\Services\Disk\FieldMap;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DiskSync
{
    private const IBLOCK_TYPE_ID = 'lists';

    public function __construct(
        private readonly BitrixWebhook $webhookClient,
        private readonly FieldMap $fieldMap,
        private readonly FileResolver $crmFileResolver,
        private readonly LocalStorage $localStorage,
    ) {}

    /**
     * Sync CRM document files referenced by a Bitrix universal list onto local disk.
     *
     * @return array{
     *     list_id: int,
     *     total_elements: int,
     *     processed_elements: int,
     *     skipped_elements: int,
     *     downloaded: int,
     *     unchanged: int,
     *     marked_deleted: int,
     *     failed: int,
     *     failed_ids: list<int|string>
     * }
     */
    public function sync(int $listId, ?int $elementId = null): array
    {
        $logger = Log::channel('bitrix_disk');
        $map = $this->fieldMap->load($listId);

        if ($map['file_fields'] === []) {
            throw new \RuntimeException('No CRM document file fields found in list '.$listId);
        }

        $items = $this->fetchElements($listId, $elementId);
        $stats = [
            'list_id' => $listId,
            'total_elements' => count($items),
            'processed_elements' => 0,
            'skipped_elements' => 0,
            'downloaded' => 0,
            'unchanged' => 0,
            'marked_deleted' => 0,
            'failed' => 0,
            'failed_ids' => [],
        ];

        foreach ($items as $item) {
            $bitrixId = (int) ($item['ID'] ?? 0);
            if ($bitrixId <= 0) {
                $stats['skipped_elements']++;

                continue;
            }

            try {
                $result = $this->syncElement($listId, $item, $map);
                if ($result['skipped']) {
                    $stats['skipped_elements']++;
                } else {
                    $stats['processed_elements']++;
                }
                $stats['downloaded'] += $result['downloaded'];
                $stats['unchanged'] += $result['unchanged'];
                $stats['marked_deleted'] += $result['marked_deleted'];
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['failed_ids'][] = $bitrixId;
                $logger->error('Disk sync element failed', [
                    'list_id' => $listId,
                    'element_id' => $bitrixId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $logger->info('Disk sync completed', $stats);

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{
     *     folder_field_id: ?string,
     *     folder_code: ?string,
     *     crm_field_id: ?string,
     *     crm_code: ?string,
     *     file_fields: array<string, array{field_id: string, code: string, name: string, slug: string}>
     * }  $map
     * @return array{downloaded: int, unchanged: int, marked_deleted: int, skipped: bool}
     */
    private function syncElement(int $listId, array $item, array $map): array
    {
        $elementId = (int) $item['ID'];
        $elementName = trim((string) ($item['NAME'] ?? ''));

        $crmRaw = $map['crm_field_id'] !== null
            ? $this->fieldMap->propertyRaw($item, (string) $map['crm_field_id'], $map['crm_code'])
            : null;
        $crmId = $this->fieldMap->parseCrmId($crmRaw);
        $entity = $this->crmFileResolver->resolveEntity($crmId, $elementName);

        if ($entity === null) {
            Log::channel('bitrix_disk')->info('Disk sync skip element without CRM entity', [
                'list_id' => $listId,
                'element_id' => $elementId,
            ]);

            return ['downloaded' => 0, 'unchanged' => 0, 'marked_deleted' => 0, 'skipped' => true];
        }

        $folderBitrixId = null;
        $leafFolderName = null;
        $folderUrl = null;

        if (($map['folder_url_field_id'] ?? null) !== null) {
            $folderUrlRaw = $this->fieldMap->propertyRaw(
                $item,
                (string) $map['folder_url_field_id'],
                $map['folder_url_code'] ?? null
            );
            $folderUrl = $this->fieldMap->parseUrl($folderUrlRaw);
        }

        if ($map['folder_field_id'] !== null) {
            $folderRaw = $this->fieldMap->propertyRaw(
                $item,
                (string) $map['folder_field_id'],
                $map['folder_code']
            );
            $folderBitrixId = $this->fieldMap->parseFolderId($folderRaw);
            if ($folderBitrixId !== null) {
                $folderMeta = $this->resolveFolderMeta($folderBitrixId);
                $leafFolderName = $folderMeta['name'];
                if ($folderUrl === null || $folderUrl === '') {
                    $folderUrl = $folderMeta['url'];
                }
            }
        }

        if ($leafFolderName === null || $leafFolderName === '') {
            $leafFolderName = $elementName !== ''
                ? $elementName
                : 'crm_'.$entity['entity_id'];
        }

        $folderName = $this->resolveFolderPath($item, $map, $folderUrl, $leafFolderName);
        $folderRelative = $this->localStorage->ensureFolder($listId, $folderName);
        $catalog = $this->crmFileResolver->loadFileCatalog(
            $entity['entity_type_id'],
            $entity['entity_id']
        );

        /** @var array<string, true> $active */
        $active = [];
        $downloaded = 0;
        $unchanged = 0;

        foreach ($map['file_fields'] as $fileField) {
            $raw = $this->fieldMap->propertyRaw($item, $fileField['field_id'], $fileField['code']);
            $fileIds = $this->fieldMap->parseFileIds($raw);

            foreach ($fileIds as $fileId) {
                $key = $fileField['code'].':'.$fileId;
                $active[$key] = true;

                $crmFile = $catalog[$fileId] ?? null;
                if ($crmFile === null) {
                    Log::channel('bitrix_disk')->warning('CRM file id missing in entity', [
                        'list_id' => $listId,
                        'element_id' => $elementId,
                        'file_id' => $fileId,
                        'field' => $fileField['code'],
                        'crm_entity_type_id' => $entity['entity_type_id'],
                        'crm_entity_id' => $entity['entity_id'],
                    ]);

                    continue;
                }

                $existing = DiskSyncedFile::query()
                    ->where('list_id', $listId)
                    ->where('element_bitrix_id', $elementId)
                    ->where('field_code', $fileField['code'])
                    ->where('bitrix_file_id', $fileId)
                    ->first();

                if (
                    $existing !== null
                    && ! $existing->is_deleted
                    && $existing->local_path !== ''
                    && Storage::disk('local')->exists($existing->local_path)
                ) {
                    $targetPath = $this->localStorage->activeRelativePath(
                        $folderRelative,
                        $fileField['slug'],
                        $fileId,
                        (string) ($existing->original_name ?: ($fileField['slug'].'_'.$fileId))
                    );

                    if ($existing->local_path !== $targetPath) {
                        $targetPath = $this->localStorage->moveFile($existing->local_path, $targetPath);
                    }

                    $existing->forceFill([
                        'folder_bitrix_id' => $folderBitrixId,
                        'folder_name' => $folderName,
                        'folder_url' => $folderUrl,
                        'local_path' => $targetPath,
                        'last_synced_at' => now(),
                    ])->save();
                    $unchanged++;

                    continue;
                }

                try {
                    $payload = $this->crmFileResolver->download($crmFile['url']);
                } catch (Throwable $e) {
                    Log::channel('bitrix_disk')->error('CRM file download failed', [
                        'list_id' => $listId,
                        'element_id' => $elementId,
                        'file_id' => $fileId,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                $originalName = $payload['filename']
                    ?? ($existing?->original_name)
                    ?? ($fileField['slug'].'_'.$fileId.$this->extensionFromMime($payload['mime']));

                $targetPath = $this->localStorage->activeRelativePath(
                    $folderRelative,
                    $fileField['slug'],
                    $fileId,
                    $originalName
                );

                if ($existing !== null
                    && ! $existing->is_deleted
                    && $existing->local_path !== ''
                    && Storage::disk('local')->exists($existing->local_path)
                    && (int) $existing->content_version !== (int) $payload['size']
                ) {
                    $this->localStorage->markDeleted(
                        $existing->local_path,
                        (int) $existing->content_version
                    );
                }

                $this->localStorage->putContents($targetPath, $payload['contents']);

                DiskSyncedFile::query()->updateOrCreate(
                    [
                        'list_id' => $listId,
                        'element_bitrix_id' => $elementId,
                        'field_code' => $fileField['code'],
                        'bitrix_file_id' => $fileId,
                    ],
                    [
                        'folder_bitrix_id' => $folderBitrixId,
                        'folder_name' => $folderName,
                        'folder_url' => $folderUrl,
                        'content_version' => (int) $payload['size'],
                        'original_name' => $originalName,
                        'local_path' => $targetPath,
                        'is_deleted' => false,
                        'last_synced_at' => now(),
                    ]
                );

                $downloaded++;
            }
        }

        $markedDeleted = $this->markMissing($listId, $elementId, $active);

        return [
            'downloaded' => $downloaded,
            'unchanged' => $unchanged,
            'marked_deleted' => $markedDeleted,
            'skipped' => false,
        ];
    }

    /**
     * @param  array<string, true>  $active
     */
    private function markMissing(int $listId, int $elementId, array $active): int
    {
        $rows = DiskSyncedFile::query()
            ->where('list_id', $listId)
            ->where('element_bitrix_id', $elementId)
            ->where('is_deleted', false)
            ->get();

        $marked = 0;
        foreach ($rows as $row) {
            $key = $row->field_code.':'.$row->bitrix_file_id;
            if (isset($active[$key])) {
                continue;
            }

            $newPath = $this->localStorage->markDeleted($row->local_path);
            $row->forceFill([
                'local_path' => $newPath,
                'is_deleted' => true,
                'last_synced_at' => now(),
            ])->save();
            $marked++;
        }

        return $marked;
    }

    /**
     * Build nested local folder path from docs URL and/or direction field.
     *
     * @param  array<string, mixed>  $item
     * @param  array{
     *     direction_field_id: ?string,
     *     direction_code: ?string,
     *     direction_values: array<string, string>
     * }  $map
     */
    private function resolveFolderPath(array $item, array $map, ?string $folderUrl, string $leafFolderName): string
    {
        $docsSegments = $this->fieldMap->pathSegmentsFromDocsUrl($folderUrl);
        if ($docsSegments !== []) {
            return $this->localStorage->sanitizeFolderPath(implode('/', $docsSegments));
        }

        $directionSegments = [];
        if (($map['direction_field_id'] ?? null) !== null) {
            $directionRaw = $this->fieldMap->propertyRaw(
                $item,
                (string) $map['direction_field_id'],
                $map['direction_code'] ?? null
            );
            $directionLabel = $this->fieldMap->resolveDirectionLabel(
                $directionRaw,
                $map['direction_values'] ?? []
            );
            $directionSegments = $this->fieldMap->directionPathSegments($directionLabel);
        }

        $path = $this->fieldMap->buildFolderPath($directionSegments, $leafFolderName);

        return $this->localStorage->sanitizeFolderPath($path !== '' ? $path : $leafFolderName);
    }

    /**
     * @return array{name: string, url: ?string}
     */
    private function resolveFolderMeta(int $folderBitrixId): array
    {
        $response = $this->webhookClient->callRaw('disk.folder.get', [
            'id' => $folderBitrixId,
        ], $this->webhookClient->diskWebhook());

        $name = trim((string) data_get($response, 'result.NAME', ''));
        if ($name === '') {
            $name = 'folder_'.$folderBitrixId;
        }

        $url = trim((string) data_get($response, 'result.DETAIL_URL', ''));

        return [
            'name' => $name,
            'url' => $url !== '' ? $url : null,
        ];
    }

    private function extensionFromMime(?string $mime): string
    {
        return match ($mime) {
            'application/pdf' => '.pdf',
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/webp' => '.webp',
            'image/gif' => '.gif',
            'application/msword' => '.doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
            default => '',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchElements(int $iblockId, ?int $elementId): array
    {
        if ($elementId !== null) {
            $response = $this->webhookClient->call('lists.element.get', [
                'IBLOCK_TYPE_ID' => self::IBLOCK_TYPE_ID,
                'IBLOCK_ID' => $iblockId,
                'ELEMENT_ID' => $elementId,
            ], $this->webhookClient->diskWebhook());

            $batch = data_get($response, 'result', []);
            if (! is_array($batch)) {
                return [];
            }

            $items = [];
            foreach ($batch as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            return $items;
        }

        $start = 0;
        $items = [];

        while (true) {
            $response = $this->webhookClient->call('lists.element.get', [
                'IBLOCK_TYPE_ID' => self::IBLOCK_TYPE_ID,
                'IBLOCK_ID' => $iblockId,
                'start' => $start,
            ], $this->webhookClient->diskWebhook());

            $batch = data_get($response, 'result', []);
            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            $next = data_get($response, 'next');
            if (! is_numeric($next)) {
                break;
            }

            $start = (int) $next;
        }

        return $items;
    }
}
