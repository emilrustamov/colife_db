<?php

namespace App\Services;

use RuntimeException;

class EnsureApartmentRepairFolders
{
    public const ENTITY_TYPE_ID = 144;

    public const FIELD_DISK_FOLDER_ID = 'ufCrm6_1785314352237';

    public const FIELD_DISK_URL = 'ufCrm6_1785314487770';

    public const FIELD_REPAIR_FOLDER_ID = 'ufCrm6_1786462131775';

    public const FIELD_PARENT_REPAIR_ID = 'parentId166';

    public const REPAIR_ENTITY_TYPE_ID = 166;

    public const FIELD_REPAIR_DISK_URL = 'ufCrm18_1791284014639';

    public const REPAIR_NAME = 'Repair';

    /**
     * @var list<string>
     */
    public const CHILD_NAMES = ['Photo', 'Estimate', 'Invoices'];

    public function __construct(
        private readonly BitrixWebhook $webhookClient,
    ) {}

    /**
     * Create Repair/Photo/Estimate/Invoices under the apartment disk folder and persist Repair id.
     *
     * @return array{
     *     apartment_id: int,
     *     title: string,
     *     folder_id: int,
     *     repair_id: ?int,
     *     created_repair: bool,
     *     created_children: list<string>,
     *     updated_card: bool,
     *     skipped: bool,
     *     skip_reason: ?string
     * }
     */
    public function ensure(array $item, bool $execute): array
    {
        $apartmentId = (int) ($item['id'] ?? 0);
        $title = trim((string) ($item['title'] ?? ''));
        $folderId = (int) ($item[self::FIELD_DISK_FOLDER_ID] ?? 0);
        $existingRepairField = $item[self::FIELD_REPAIR_FOLDER_ID] ?? null;
        $existingRepairId = is_numeric($existingRepairField) ? (int) $existingRepairField : 0;

        $result = [
            'apartment_id' => $apartmentId,
            'title' => $title,
            'folder_id' => $folderId,
            'repair_id' => $existingRepairId > 0 ? $existingRepairId : null,
            'created_repair' => false,
            'created_children' => [],
            'updated_card' => false,
            'skipped' => false,
            'skip_reason' => null,
        ];

        if (! $this->isActiveStage((string) ($item['stageId'] ?? ''))) {
            $result['skipped'] = true;
            $result['skip_reason'] = 'inactive_stage';

            return $result;
        }

        if ($apartmentId <= 0 || $folderId <= 0) {
            $result['skipped'] = true;
            $result['skip_reason'] = 'no_disk_folder_id';

            return $result;
        }

        $children = $this->folderChildren($folderId);
        $repairId = $this->findFolderId($children, self::REPAIR_NAME);
        $createdRepair = false;

        if ($repairId === null) {
            if (! $execute) {
                $result['created_repair'] = true;
                $result['created_children'] = self::CHILD_NAMES;
                $result['updated_card'] = true;

                return $result;
            }

            $repairId = $this->addSubFolder($folderId, self::REPAIR_NAME);
            $createdRepair = true;
            $result['created_repair'] = true;
        }

        $result['repair_id'] = $repairId;
        $repairChildren = $this->folderChildren($repairId);

        foreach (self::CHILD_NAMES as $name) {
            if ($this->findFolderId($repairChildren, $name) !== null) {
                continue;
            }

            if (! $execute) {
                $result['created_children'][] = $name;

                continue;
            }

            $this->addSubFolder($repairId, $name);
            $result['created_children'][] = $name;
        }

        $shouldWriteCard = $createdRepair || $existingRepairId !== $repairId;
        if ($shouldWriteCard && $execute) {
            $this->writeRepairId($apartmentId, $repairId);
            $result['updated_card'] = true;
        } elseif ($shouldWriteCard && ! $execute) {
            $result['updated_card'] = true;
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listApartments(): array
    {
        $items = [];
        $start = 0;

        do {
            $response = $this->webhookClient->callRaw('crm.item.list', [
                'entityTypeId' => self::ENTITY_TYPE_ID,
                'select' => [
                    'id',
                    'title',
                    'stageId',
                    self::FIELD_DISK_FOLDER_ID,
                    self::FIELD_DISK_URL,
                    self::FIELD_REPAIR_FOLDER_ID,
                    self::FIELD_PARENT_REPAIR_ID,
                ],
                'start' => $start,
            ], $this->webhookClient->diskWebhook());

            $this->assertOk($response, 'crm.item.list');

            $page = data_get($response, 'result.items', []);
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
     * Write Repair folder URL into the related Repair SPA (type 166).
     *
     * @return array{
     *     apartment_id: int,
     *     title: string,
     *     repair_spa_id: ?int,
     *     url: ?string,
     *     updated: bool,
     *     skipped: bool,
     *     skip_reason: ?string
     * }
     */
    public function fillRepairSpaDiskUrl(array $item, bool $execute): array
    {
        $apartmentId = (int) ($item['id'] ?? 0);
        $title = trim((string) ($item['title'] ?? ''));
        $repairSpaId = (int) ($item[self::FIELD_PARENT_REPAIR_ID] ?? 0);
        $repairFolderId = (int) ($item[self::FIELD_REPAIR_FOLDER_ID] ?? 0);

        $result = [
            'apartment_id' => $apartmentId,
            'title' => $title,
            'repair_spa_id' => $repairSpaId > 0 ? $repairSpaId : null,
            'url' => null,
            'updated' => false,
            'skipped' => false,
            'skip_reason' => null,
        ];

        if (! $this->isActiveStage((string) ($item['stageId'] ?? ''))) {
            $result['skipped'] = true;
            $result['skip_reason'] = 'inactive_stage';

            return $result;
        }

        if ($repairSpaId <= 0) {
            $result['skipped'] = true;
            $result['skip_reason'] = 'no_repair_spa';

            return $result;
        }

        if ($repairFolderId <= 0) {
            $result['skipped'] = true;
            $result['skip_reason'] = 'no_repair_folder_id';

            return $result;
        }

        $url = $this->repairFolderUrl($item, $repairFolderId);
        $result['url'] = $url;

        if (! $execute) {
            $result['updated'] = true;

            return $result;
        }

        $response = $this->updateRepairDiskUrl($repairSpaId, $url);
        $this->assertOk($response, 'crm.item.update');
        $result['updated'] = true;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function repairFolderUrl(array $item, int $repairFolderId): string
    {
        $base = trim((string) ($item[self::FIELD_DISK_URL] ?? ''));
        if ($base !== '') {
            return rtrim($base, '/').'/Repair';
        }

        return $this->folderDetailUrl($repairFolderId);
    }

    /**
     * @return array<string, mixed>
     */
    private function updateRepairDiskUrl(int $repairSpaId, string $url): array
    {
        $attempts = 0;
        $response = [];

        do {
            if ($attempts > 0) {
                sleep(min(20, 4 * $attempts));
            }

            $response = $this->webhookClient->callRaw('crm.item.update', [
                'entityTypeId' => self::REPAIR_ENTITY_TYPE_ID,
                'id' => $repairSpaId,
                'fields' => [
                    self::FIELD_REPAIR_DISK_URL => $url,
                ],
            ], $this->webhookClient->diskWebhook());

            $error = (string) data_get($response, 'error', '');
            $attempts++;
        } while (
            $attempts < 5
            && in_array($error, ['QUERY_LIMIT_EXCEEDED', 'OPERATION_TIME_LIMIT', 'INTERNAL_SERVER_ERROR'], true)
        );

        return $response;
    }

    private function folderDetailUrl(int $folderId): string
    {
        $response = $this->webhookClient->callRaw('disk.folder.get', [
            'id' => $folderId,
        ], $this->webhookClient->diskWebhook());

        $this->assertOk($response, 'disk.folder.get');

        $url = trim((string) data_get($response, 'result.DETAIL_URL', ''));
        if ($url === '') {
            throw new RuntimeException('disk.folder.get returned empty DETAIL_URL for '.$folderId);
        }

        return $url;
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
     */
    private function findFolderId(array $children, string $name): ?int
    {
        foreach ($children as $child) {
            $type = strtolower((string) ($child['TYPE'] ?? ''));
            if ($type !== 'folder') {
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

    private function addSubFolder(int $parentId, string $name): int
    {
        $response = $this->webhookClient->callRaw('disk.folder.addSubFolder', [
            'id' => $parentId,
            'data' => ['NAME' => $name],
        ], $this->webhookClient->diskWebhook());

        $this->assertOk($response, 'disk.folder.addSubFolder');

        $id = (int) data_get($response, 'result.ID', 0);
        if ($id <= 0) {
            throw new RuntimeException('disk.folder.addSubFolder returned no ID for '.$name);
        }

        return $id;
    }

    private function writeRepairId(int $apartmentId, int $repairFolderId): void
    {
        $response = $this->webhookClient->callRaw('crm.item.update', [
            'entityTypeId' => self::ENTITY_TYPE_ID,
            'id' => $apartmentId,
            'fields' => [
                self::FIELD_REPAIR_FOLDER_ID => (string) $repairFolderId,
            ],
        ], $this->webhookClient->diskWebhook());

        $this->assertOk($response, 'crm.item.update');
    }

    /**
     * Skip Success, Ex-apartment (FAIL) and Termination process stages.
     */
    private function isActiveStage(string $stageId): bool
    {
        if ($stageId === '') {
            return true;
        }

        return ! in_array($stageId, LandlordContactsSync::EXCLUDED_STAGE_IDS, true);
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
