<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CleaningSubmitService
{
    public function __construct(
        private readonly BitrixRest $bitrixRest,
        private readonly BitrixOAuth $oauth
    ) {}

    /**
     * Upload photos, create tasks and add a timeline comment on the selected unit.
     *
     * @param  array{
     *     apartment_id: int,
     *     apartment_title: string,
     *     unit_id: int,
     *     unit_title: string,
     *     cleaning_type: string,
     *     date: string,
     *     time: string,
     *     staff: string,
     *     checklist: list<string>,
     *     checklist_other: string,
     *     needs_repair: bool,
     *     notes: string
     * }  $payload
     * @param  list<UploadedFile>  $photos
     * @return array{task_id: int, repair_task_id: int|null, comment_id: int, file_ids: list<int>}
     */
    public function submit(array $payload, array $photos = []): array
    {
        $domain = $this->oauth->normalizeDomain((string) config('services.b24_tr.portal_domain'));
        if ($domain === '') {
            throw new RuntimeException('TR portal domain is not configured.');
        }

        $this->warnIfTokenIsNotSystemAccount($domain);

        $logger = Log::channel('bitrix_cleaning');
        $fileIds = $this->uploadPhotos($domain, $payload, $photos);
        $diskFileRefs = array_map(static fn (int $id): string => 'n'.$id, $fileIds);

        $commentId = $this->addUnitComment($domain, $payload, $fileIds);
        $taskId = $this->createMaintenanceTask($domain, $payload, $diskFileRefs);

        $repairTaskId = null;
        if (! empty($payload['needs_repair'])) {
            $repairTaskId = $this->createRepairTask($domain, $payload, $diskFileRefs);
        }

        $logger->info('CLEANING_SUBMIT_OK', [
            'domain' => $domain,
            'apartment_id' => $payload['apartment_id'],
            'unit_id' => $payload['unit_id'],
            'task_id' => $taskId,
            'repair_task_id' => $repairTaskId,
            'comment_id' => $commentId,
            'file_ids' => $fileIds,
        ]);

        return [
            'task_id' => $taskId,
            'repair_task_id' => $repairTaskId,
            'comment_id' => $commentId,
            'file_ids' => $fileIds,
        ];
    }

    /**
     * Timeline comments are authored by the OAuth token owner (app installer).
     * CREATED_BY on tasks can still be the system account even if install user differs.
     */
    private function warnIfTokenIsNotSystemAccount(string $domain): void
    {
        $expectedUserId = (int) config('services.cleaning.system_user_id', 71857);

        try {
            $current = $this->bitrixRest->postJson('user.current', [], $domain);
            $actualUserId = (int) data_get($current, 'result.ID', 0);
        } catch (\Throwable) {
            return;
        }

        if ($actualUserId > 0 && $actualUserId !== $expectedUserId) {
            Log::channel('bitrix_cleaning')->warning('CLEANING_TOKEN_NOT_SYSTEM_USER', [
                'expected' => $expectedUserId,
                'actual' => $actualUserId,
                'domain' => $domain,
                'note' => 'Timeline comments will be authored by the install user, not the system account.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<UploadedFile>  $photos
     * @return list<int>
     */
    private function uploadPhotos(string $domain, array $payload, array $photos): array
    {
        if ($photos === []) {
            return [];
        }

        $folderId = $this->resolveUploadFolderId($domain, $payload);

        $ids = [];
        foreach ($photos as $photo) {
            if (! $photo instanceof UploadedFile || ! $photo->isValid()) {
                continue;
            }

            $name = $photo->getClientOriginalName() ?: ('photo_'.uniqid('', true).'.jpg');
            $content = base64_encode((string) file_get_contents($photo->getRealPath()));
            $response = $this->bitrixRest->postJson('disk.folder.uploadfile', [
                'id' => $folderId,
                'data' => ['NAME' => $name],
                'fileContent' => [$name, $content],
                'generateUniqueName' => true,
            ], $domain);

            $fileId = (int) data_get($response, 'result.ID', 0);
            if ($fileId > 0) {
                $ids[] = $fileId;
            }
        }

        return $ids;
    }

    /**
     * Build Cleaning/YYYY/MM/DD_Apartment folder chain and return leaf folder id.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveUploadFolderId(string $domain, array $payload): int
    {
        $rootId = (int) config('services.cleaning.disk_folder_id', 396621);
        if ($rootId <= 0) {
            $rootId = $this->resolveCleaningRootFolderId($domain);
        }

        [$year, $month, $day] = $this->resolveReportDateParts((string) ($payload['date'] ?? ''));
        $apartmentFolder = $this->sanitizeFolderName(
            $day.'_'.trim((string) ($payload['apartment_title'] ?? ('apartment_'.(int) ($payload['apartment_id'] ?? 0))))
        );

        $yearId = $this->ensureSubfolder($domain, $rootId, $year);
        $monthId = $this->ensureSubfolder($domain, $yearId, $month);

        return $this->ensureSubfolder($domain, $monthId, $apartmentFolder);
    }

    /**
     * Find shared-disk "Cleaning" folder when CLEANING_DISK_FOLDER_ID is not set.
     */
    private function resolveCleaningRootFolderId(string $domain): int
    {
        $storages = $this->bitrixRest->postJson('disk.storage.getlist', [], $domain);
        foreach (data_get($storages, 'result', []) as $storage) {
            if (! is_array($storage)) {
                continue;
            }
            if (strcasecmp((string) ($storage['NAME'] ?? ''), 'Cleaning') === 0
                && (string) ($storage['ENTITY_TYPE'] ?? '') === 'common'
            ) {
                $rootId = (int) ($storage['ROOT_OBJECT_ID'] ?? 0);
                if ($rootId > 0) {
                    return $rootId;
                }
            }
        }

        foreach (data_get($storages, 'result', []) as $storage) {
            if (! is_array($storage) || (string) ($storage['ENTITY_TYPE'] ?? '') !== 'common') {
                continue;
            }
            $storageId = (int) ($storage['ID'] ?? 0);
            if ($storageId <= 0) {
                continue;
            }
            $children = $this->bitrixRest->postJson('disk.storage.getchildren', ['id' => $storageId], $domain);
            foreach (data_get($children, 'result', []) as $child) {
                if (! is_array($child)) {
                    continue;
                }
                if (strcasecmp((string) ($child['NAME'] ?? ''), 'Cleaning') === 0
                    && (string) ($child['TYPE'] ?? '') === 'folder'
                ) {
                    $id = (int) ($child['ID'] ?? 0);
                    if ($id > 0) {
                        return $id;
                    }
                }
            }
        }

        throw new RuntimeException('Cleaning disk folder was not found.');
    }

    /**
     * Ensure a child folder exists under parent and return its id.
     */
    private function ensureSubfolder(string $domain, int $parentId, string $name): int
    {
        $existingId = $this->findChildFolderId($domain, $parentId, $name);
        if ($existingId > 0) {
            return $existingId;
        }

        $response = $this->bitrixRest->postJson('disk.folder.addsubfolder', [
            'id' => $parentId,
            'data' => ['NAME' => $name],
        ], $domain);

        $createdId = (int) data_get($response, 'result.ID', 0);
        if ($createdId > 0) {
            return $createdId;
        }

        $error = (string) data_get($response, 'error', '');
        if ($error !== '' || data_get($response, 'error_description')) {
            $existingId = $this->findChildFolderId($domain, $parentId, $name);
            if ($existingId > 0) {
                return $existingId;
            }
        }

        throw new RuntimeException('Unable to create disk folder: '.$name);
    }

    /**
     * Find folder id by name among parent children.
     */
    private function findChildFolderId(string $domain, int $parentId, string $name): int
    {
        $start = 0;
        while (true) {
            $response = $this->bitrixRest->postJson('disk.folder.getchildren', [
                'id' => $parentId,
                'filter' => [
                    'NAME' => $name,
                ],
                'start' => $start,
            ], $domain);

            foreach (data_get($response, 'result', []) as $child) {
                if (! is_array($child)) {
                    continue;
                }
                if ((string) ($child['TYPE'] ?? '') !== 'folder') {
                    continue;
                }
                if (strcasecmp((string) ($child['NAME'] ?? ''), $name) === 0) {
                    return (int) ($child['ID'] ?? 0);
                }
            }

            $next = data_get($response, 'next');
            if ($next === null) {
                break;
            }
            $start = (int) $next;
        }

        return 0;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveReportDateParts(string $date): array
    {
        $date = trim($date);
        try {
            if (preg_match('/^(\d{2})[\/.\-](\d{2})[\/.\-](\d{4})$/', $date, $m) === 1) {
                $carbon = \Carbon\Carbon::createFromFormat('d.m.Y', $m[1].'.'.$m[2].'.'.$m[3]);
            } else {
                $carbon = $date !== '' ? \Carbon\Carbon::parse($date) : now();
            }
        } catch (\Throwable) {
            $carbon = now();
        }

        return [
            $carbon->format('Y'),
            $carbon->format('m'),
            $carbon->format('d'),
        ];
    }

    /**
     * Keep Bitrix folder names filesystem-safe.
     */
    private function sanitizeFolderName(string $name): string
    {
        $name = trim($name);
        $name = (string) preg_replace('/[\\\\\/:\*\?"<>\|]+/u', '-', $name);
        $name = (string) preg_replace('/\s+/u', ' ', $name);
        $name = trim($name, " .-_");

        return $name !== '' ? mb_substr($name, 0, 100) : 'apartment';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<int>  $fileIds
     */
    private function addUnitComment(string $domain, array $payload, array $fileIds): int
    {
        $unitTypeId = (int) config('services.cleaning.unit_entity_type_id', 144);
        $comment = $this->buildReportText($payload, $fileIds);

        $response = $this->bitrixRest->postJson('crm.timeline.comment.add', [
            'fields' => [
                'ENTITY_ID' => (int) $payload['unit_id'],
                'ENTITY_TYPE' => 'dynamic_'.$unitTypeId,
                'COMMENT' => $comment,
            ],
        ], $domain);

        return (int) data_get($response, 'result', 0);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $diskFileRefs
     */
    private function createMaintenanceTask(string $domain, array $payload, array $diskFileRefs): int
    {
        $cleaningType = trim((string) ($payload['cleaning_type'] ?? 'Cleaning'));
        if ($cleaningType === '') {
            $cleaningType = 'Cleaning';
        }

        $title = sprintf(
            '%s_ %s_ %s',
            $cleaningType,
            $this->formatTitleDate((string) $payload['date']),
            trim((string) $payload['apartment_title'])
        );

        $fields = [
            'TITLE' => $title,
            'DESCRIPTION' => $this->buildReportText($payload, []),
            'RESPONSIBLE_ID' => (int) config('services.cleaning.assignee_id', 62997),
            'CREATED_BY' => (int) config('services.cleaning.system_user_id', 71857),
            'GROUP_ID' => (int) config('services.cleaning.cleaning_group_id', 13),
            'DEADLINE' => now()->addDays((int) config('services.cleaning.deadline_days', 2))->format('c'),
            'ACCOMPLICES' => [(int) config('services.cleaning.participant_id', 110103)],
            'AUDITORS' => $this->observerIds(),
            'UF_CRM_TASK' => $this->crmBindings($payload, true, true),
        ];

        if ($diskFileRefs !== []) {
            $fields['UF_TASK_WEBDAV_FILES'] = $diskFileRefs;
        }

        $response = $this->bitrixRest->postJson('tasks.task.add', [
            'fields' => $fields,
        ], $domain);

        return (int) data_get($response, 'result.task.id', data_get($response, 'result.task.ID', 0));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $diskFileRefs
     */
    private function createRepairTask(string $domain, array $payload, array $diskFileRefs): int
    {
        $title = sprintf(
            'Repair needed_ %s_ %s',
            $this->formatTitleDate((string) $payload['date']),
            trim((string) $payload['apartment_title'])
        );

        $description = $this->buildRepairDescription($payload);

        $fields = [
            'TITLE' => $title,
            'DESCRIPTION' => $description,
            'RESPONSIBLE_ID' => (int) config('services.cleaning.assignee_id', 62997),
            'CREATED_BY' => (int) config('services.cleaning.system_user_id', 71857),
            'GROUP_ID' => (int) config('services.cleaning.housemaster_group_id', 27),
            'DEADLINE' => now()->addDays((int) config('services.cleaning.deadline_days', 2))->format('c'),
            'AUDITORS' => $this->observerIds(),
            'UF_CRM_TASK' => $this->crmBindings($payload, true, true),
        ];

        if ($diskFileRefs !== []) {
            $fields['UF_TASK_WEBDAV_FILES'] = $diskFileRefs;
        }

        $response = $this->bitrixRest->postJson('tasks.task.add', [
            'fields' => $fields,
        ], $domain);

        return (int) data_get($response, 'result.task.id', data_get($response, 'result.task.ID', 0));
    }

    /**
     * Observers for auto-created tasks (Bitrix field AUDITORS).
     *
     * @return list<int>
     */
    private function observerIds(): array
    {
        $ids = config('services.cleaning.observer_ids', [109833, 87311, 25681]);
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
    }

    /**
     * Build UF_CRM_TASK bindings for Unit / Apartment SPA items.
     * SPA prefix = "T" + dechex(entityTypeId), e.g. 144 -> T90_, 165 -> Ta5_.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function crmBindings(array $payload, bool $withUnit, bool $withApartment): array
    {
        $bindings = [];

        if ($withUnit) {
            $unitId = (int) ($payload['unit_id'] ?? 0);
            if ($unitId > 0) {
                $bindings[] = $this->spaCrmValue(
                    (int) config('services.cleaning.unit_entity_type_id', 144),
                    $unitId
                );
            }
        }

        if ($withApartment) {
            $apartmentId = (int) ($payload['apartment_id'] ?? 0);
            if ($apartmentId > 0) {
                $bindings[] = $this->spaCrmValue(
                    (int) config('services.cleaning.apartment_entity_type_id', 165),
                    $apartmentId
                );
            }
        }

        return $bindings;
    }

    /**
     * Format SPA CRM binding value for UF_CRM_TASK.
     */
    private function spaCrmValue(int $entityTypeId, int $itemId): string
    {
        return 'T'.dechex($entityTypeId).'_'.$itemId;
    }

    /**
     * Short description for housemaster repair task (no full cleaning report).
     *
     * @param  array<string, mixed>  $payload
     */
    private function buildRepairDescription(array $payload): string
    {
        $lines = [
            'Repair requested after cleaning check-out.',
            'Apartment: '.trim((string) $payload['apartment_title']),
            'Unit: '.trim((string) $payload['unit_title']),
            'Date: '.trim((string) $payload['date']),
        ];

        $notes = trim((string) ($payload['notes'] ?? ''));
        if ($notes !== '') {
            $lines[] = 'Notes: '.$notes;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<int>  $fileIds
     */
    private function buildReportText(array $payload, array $fileIds): string
    {
        $checklist = $payload['checklist'] ?? [];
        if (! is_array($checklist)) {
            $checklist = [];
        }
        $checklist = array_values(array_filter(
            array_map(static fn ($item): string => trim((string) $item), $checklist),
            static fn (string $item): bool => $item !== '' && strcasecmp($item, 'Other') !== 0
        ));

        $other = trim((string) ($payload['checklist_other'] ?? ''));
        if ($other !== '') {
            $checklist[] = 'Other: '.$other;
        }

        $lines = [
            'Cleaning Report',
            'Apartment: '.trim((string) $payload['apartment_title']).' (#'.(int) $payload['apartment_id'].')',
            'Unit: '.trim((string) $payload['unit_title']).' (#'.(int) $payload['unit_id'].')',
            'Cleaning type: '.trim((string) $payload['cleaning_type']),
            'Date: '.trim((string) $payload['date']),
            'Time: '.trim((string) $payload['time']),
            'Cleaning staff: '.trim((string) $payload['staff']),
            'Checklist: '.($checklist !== [] ? implode(', ', $checklist) : '—'),
            'Needs repair: '.(! empty($payload['needs_repair']) ? 'Yes' : 'No'),
            'Notes: '.(trim((string) ($payload['notes'] ?? '')) !== '' ? trim((string) $payload['notes']) : '—'),
        ];

        if ($fileIds !== []) {
            $lines[] = 'Photos (Disk file IDs): '.implode(', ', $fileIds);
        }

        return implode("\n", $lines);
    }

    /**
     * Normalize form date to DD.MM.YYYY for task titles.
     */
    private function formatTitleDate(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return now()->format('d.m.Y');
        }

        if (preg_match('/^(\d{2})[\/.\-](\d{2})[\/.\-](\d{4})$/', $date, $m) === 1) {
            return $m[1].'.'.$m[2].'.'.$m[3];
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) === 1) {
            return $m[3].'.'.$m[2].'.'.$m[1];
        }

        try {
            return \Carbon\Carbon::parse($date)->format('d.m.Y');
        } catch (\Throwable) {
            return $date;
        }
    }
}
