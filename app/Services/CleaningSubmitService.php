<?php

namespace App\Services;

use App\Support\EntityMap;
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
     *     notes: string,
     *     is_common_area?: bool
     * }  $payload
     * @param  list<UploadedFile>  $photos
     * @param  list<UploadedFile>  $receipts
     * @return array{task_id: int, repair_task_id: int|null, comment_id: int, unit_comment_id: int, file_ids: list<int>, receipt_ids: list<int>}
     */
    public function submit(array $payload, array $photos = [], array $receipts = []): array
    {
        $domain = $this->oauth->normalizeDomain((string) config('services.b24_tr.portal_domain'));
        if ($domain === '') {
            throw new RuntimeException('TR portal domain is not configured.');
        }

        $this->warnIfNotSystemUser($domain);

        $logger = Log::channel('bitrix_cleaning');
        [$fileIds, $photoFiles] = $this->uploadPhotos($domain, $payload, $photos);
        [$receiptIds, $receiptFiles] = $this->uploadPhotos($domain, $payload, $receipts, 'receipt_');
        $diskFileRefs = array_map(static fn (int $id): string => 'n'.$id, $fileIds);
        $receiptRefs = array_map(static fn (int $id): string => 'n'.$id, $receiptIds);

        $taskFiles = array_merge($diskFileRefs, $receiptRefs);
        $taskId = $this->createMaintenanceTask($domain, $payload, $taskFiles);

        $repairTaskId = null;
        if (! empty($payload['needs_repair'])) {
            $repairTaskId = $this->createRepairTask($domain, $payload, $diskFileRefs);
        }

        $apartmentCommentId = $this->addTimelineComment(
            $domain,
            $this->cleaningApartmentTypeId(),
            (int) $payload['apartment_id'],
            $this->withTaskLinks($this->buildReportText($payload, []), $domain, $taskId, $repairTaskId),
            array_merge($photoFiles, $receiptFiles),
        );
        $unitCommentId = $apartmentCommentId;
        if (empty($payload['is_common_area'])) {
            $unitCommentId = $this->addTimelineComment(
                $domain,
                $this->cleaningUnitTypeId(),
                (int) $payload['unit_id'],
                $this->withTaskLinks($this->buildReportText($payload, $fileIds, $receiptIds), $domain, $taskId, $repairTaskId),
            );
        }

        $logger->info('CLEANING_SUBMIT_OK', [
            'domain' => $domain,
            'apartment_id' => $payload['apartment_id'],
            'unit_id' => $payload['unit_id'],
            'task_id' => $taskId,
            'repair_task_id' => $repairTaskId,
            'comment_id' => $apartmentCommentId,
            'unit_comment_id' => $unitCommentId,
            'file_ids' => $fileIds,
            'receipt_ids' => $receiptIds,
        ]);

        return [
            'task_id' => $taskId,
            'repair_task_id' => $repairTaskId,
            'comment_id' => $apartmentCommentId,
            'unit_comment_id' => $unitCommentId,
            'file_ids' => $fileIds,
            'receipt_ids' => $receiptIds,
        ];
    }

    /**
     * Timeline comments are authored by the OAuth token owner (app installer).
     * CREATED_BY on tasks can still be the system account even if install user differs.
     */
    private function warnIfNotSystemUser(string $domain): void
    {
        $expectedUserId = (int) config('services.cleaning.system_user_id');

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
     * @return array{0: list<int>, 1: list<array{0: string, 1: string}>}
     */
    private function uploadPhotos(string $domain, array $payload, array $photos, string $namePrefix = ''): array
    {
        if ($photos === []) {
            return [[], []];
        }

        $folderId = $this->resolveUploadFolderId($domain, $payload);

        $ids = [];
        $commentFiles = [];
        foreach ($photos as $photo) {
            if (! $photo instanceof UploadedFile) {
                continue;
            }
            if (! $photo->isValid()) {
                throw new RuntimeException('A photo could not be uploaded. Please choose a smaller image.');
            }

            [$name, $binary] = $this->normalizePhotoToJpeg($photo, $namePrefix);
            $content = base64_encode($binary);
            $response = $this->bitrixRest->postJson('disk.folder.uploadfile', [
                'id' => $folderId,
                'data' => ['NAME' => $name],
                'fileContent' => [$name, $content],
                'generateUniqueName' => true,
            ], $domain);

            $fileId = (int) data_get($response, 'result.ID', 0);
            if ($fileId > 0) {
                $ids[] = $fileId;
                $commentFiles[] = [$name, $content];
            }
        }

        return [$ids, $commentFiles];
    }

    /**
     * Convert upload to JPEG so Bitrix mobile can open photos in the usual viewer.
     *
     * @return array{0: string, 1: string}
     */
    private function normalizePhotoToJpeg(UploadedFile $photo, string $namePrefix = ''): array
    {
        $original = $photo->getClientOriginalName() ?: ('photo_'.uniqid('', true).'.jpg');
        $base = pathinfo($original, PATHINFO_FILENAME);
        $base = $this->sanitizeFolderName($base !== '' ? $base : 'photo');
        $name = $namePrefix.$base.'.jpg';

        $raw = (string) file_get_contents($photo->getRealPath());
        if ($raw === '') {
            throw new RuntimeException('A photo could not be read. Please try another image.');
        }

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return [$name, $raw];
        }

        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            throw new RuntimeException(
                'This photo format cannot be opened on mobile Bitrix. Please take or choose a JPEG/PNG photo.'
            );
        }

        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($image);
        }
        if (function_exists('imagealphablending')) {
            imagealphablending($image, true);
        }
        if (function_exists('imagesavealpha')) {
            imagesavealpha($image, false);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $maxSide = 1600;
        if ($width > 0 && $height > 0 && max($width, $height) > $maxSide) {
            $scale = $maxSide / max($width, $height);
            $newW = max(1, (int) round($width * $scale));
            $newH = max(1, (int) round($height * $scale));
            $resized = imagecreatetruecolor($newW, $newH);
            if ($resized !== false) {
                $white = imagecolorallocate($resized, 255, 255, 255);
                imagefilledrectangle($resized, 0, 0, $newW, $newH, $white);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);
                imagedestroy($image);
                $image = $resized;
            }
        }

        ob_start();
        $ok = imagejpeg($image, null, 82);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        if (! $ok || $jpeg === '') {
            throw new RuntimeException('A photo could not be converted for mobile Bitrix. Please try another image.');
        }

        return [$name, $jpeg];
    }

    /**
     * Build Cleaning/YYYY/MM/DD_Apartment folder chain and return leaf folder id.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveUploadFolderId(string $domain, array $payload): int
    {
        $rootId = (int) config('services.cleaning.disk_folder_id');
        if ($rootId <= 0) {
            $rootId = $this->cleaningRootFolder($domain);
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
    private function cleaningRootFolder(string $domain): int
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
     * Append clickable task links for the apartment and room timeline comments.
     */
    private function withTaskLinks(string $comment, string $domain, int $taskId, ?int $repairTaskId): string
    {
        $lines = [$comment, ''];
        if ($taskId > 0) {
            $lines[] = $this->reportField('Task', '[url='.$this->taskUrl($domain, $taskId).']#'.$taskId.'[/url]');
        }
        if ($repairTaskId !== null && $repairTaskId > 0) {
            $lines[] = $this->reportField('Repair task', '[url='.$this->taskUrl($domain, $repairTaskId).']#'.$repairTaskId.'[/url]');
        }

        return implode("\n", $lines);
    }

    /**
     * Build a portal URL that opens the task for the current Bitrix user.
     */
    private function taskUrl(string $domain, int $taskId): string
    {
        return 'https://'.$domain.'/company/personal/user/0/tasks/task/view/'.$taskId.'/';
    }

    /**
     * Add a CRM timeline comment and attach photo contents so the timeline shows the images.
     *
     * @param  list<array{0: string, 1: string}>  $files
     */
    private function addTimelineComment(string $domain, int $entityTypeId, int $entityId, string $comment, array $files = []): int
    {
        $fields = [
            'ENTITY_ID' => $entityId,
            'ENTITY_TYPE' => 'dynamic_'.$entityTypeId,
            'COMMENT' => $comment,
        ];
        if ($files !== []) {
            $fields['FILES'] = $files;
        }

        $response = $this->bitrixRest->postJson('crm.timeline.comment.add', [
            'fields' => $fields,
        ], $domain);

        return (int) data_get($response, 'result', 0);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $diskFileRefs
     */
    private function createMaintenanceTask(string $domain, array $payload, array $diskFileRefs): int
    {
        $title = $this->buildCleaningTaskTitle($payload);

        $fields = [
            'TITLE' => $title,
            'DESCRIPTION' => $this->buildReportText($payload, []),
            'RESPONSIBLE_ID' => (int) config('services.cleaning.assignee_id'),
            'CREATED_BY' => (int) config('services.cleaning.system_user_id'),
            'GROUP_ID' => (int) config('services.cleaning.cleaning_group_id'),
            'DEADLINE' => now()->addDays((int) config('services.cleaning.deadline_days', 2))->format('c'),
            'ACCOMPLICES' => [(int) config('services.cleaning.participant_id')],
            'AUDITORS' => $this->observerIds(),
            'UF_CRM_TASK' => $this->crmBindings($payload, empty($payload['is_common_area']), true),
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
            'RESPONSIBLE_ID' => (int) config('services.cleaning.assignee_id'),
            'CREATED_BY' => (int) config('services.cleaning.system_user_id'),
            'GROUP_ID' => (int) config('services.cleaning.housemaster_group_id'),
            'DEADLINE' => now()->addDays((int) config('services.cleaning.deadline_days', 2))->format('c'),
            'AUDITORS' => $this->observerIds(),
            'UF_CRM_TASK' => $this->crmBindings($payload, empty($payload['is_common_area']), true),
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
        $ids = config('services.cleaning.observer_ids', []);
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
                    $this->cleaningUnitTypeId(),
                    $unitId
                );
            }
        }

        if ($withApartment) {
            $apartmentId = (int) ($payload['apartment_id'] ?? 0);
            if ($apartmentId > 0) {
                $bindings[] = $this->spaCrmValue(
                    $this->cleaningApartmentTypeId(),
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
            'Date: '.$this->formatTitleDate((string) $payload['date']),
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
     * @param  list<int>  $receiptIds
     */
    private function buildReportText(array $payload, array $fileIds, array $receiptIds = []): string
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
            '[color=#1F8A3B][b]Cleaning Report[/b][/color]',
            $this->reportField('Apartment', trim((string) $payload['apartment_title']).' (#'.(int) $payload['apartment_id'].')'),
            $this->reportField('Unit', trim((string) $payload['unit_title']).' (#'.(int) $payload['unit_id'].')'),
            $this->reportField('Cleaning type', trim((string) $payload['cleaning_type'])),
            $this->reportField('Date', $this->formatTitleDate((string) $payload['date'])),
            $this->reportField('Time', trim((string) $payload['time'])),
            $this->reportField('Cleaning staff', trim((string) $payload['staff'])),
            $this->reportField('Needs repair', ! empty($payload['needs_repair']) ? 'Yes' : 'No'),
        ];
        if ($checklist !== []) {
            $lines[] = $this->reportField('Checklist', implode(', ', $checklist));
        }
        $notes = trim((string) ($payload['notes'] ?? ''));
        if ($notes !== '') {
            $lines[] = $this->reportField('Notes', $notes);
        }

        if ($fileIds !== []) {
            $lines[] = $this->reportField('Photos (Disk file IDs)', implode(', ', $fileIds));
        }
        if ($receiptIds !== []) {
            $lines[] = $this->reportField('Receipts (Disk file IDs)', implode(', ', $receiptIds));
        }

        return implode("\n", $lines);
    }

    /**
     * Bold label for a report line in the timeline comment and task description.
     */
    private function reportField(string $label, string $value): string
    {
        return '[b]'.$label.':[/b] '.$value;
    }

    /**
     * Build cleaning task title: "{Apartment} Unit {Unit} Cleaning".
     *
     * @param  array<string, mixed>  $payload
     */
    private function buildCleaningTaskTitle(array $payload): string
    {
        $apartment = trim((string) ($payload['apartment_title'] ?? ''));
        if ($apartment === '') {
            $apartment = 'Apartment';
        }

        if (! empty($payload['is_common_area'])) {
            return trim($apartment.' Common area Cleaning');
        }

        $unit = trim((string) ($payload['unit_title'] ?? ''));
        if ($unit === '') {
            $unit = '#'.(int) ($payload['unit_id'] ?? 0);
        }

        if (preg_match('/^unit\b/iu', $unit) === 1) {
            return trim($apartment.' '.$unit.' Cleaning');
        }

        return trim($apartment.' Unit '.$unit.' Cleaning');
    }

    /**
     * Normalize a form date to DD.MM.YYYY.
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

    /**
     * TR cleaning unit SPA type id from portal entity map / env.
     */
    private function cleaningUnitTypeId(): int
    {
        $typeId = (int) config('services.cleaning.unit_entity_type_id');
        if ($typeId > 0) {
            return $typeId;
        }

        return EntityMap::typeId('tr', 'units');
    }

    /**
     * TR cleaning apartment SPA type id from portal entity map / env.
     */
    private function cleaningApartmentTypeId(): int
    {
        $typeId = (int) config('services.cleaning.apartment_entity_type_id');
        if ($typeId > 0) {
            return $typeId;
        }

        return EntityMap::typeId('tr', 'apartments');
    }
}
