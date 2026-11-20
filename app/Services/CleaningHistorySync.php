<?php

namespace App\Services;

use App\Models\CleaningReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CleaningHistorySync
{
    public function __construct(
        private readonly BitrixRest $bitrixRest,
        private readonly BitrixOAuth $oauth,
    ) {}

    /**
     * Pull Cleaning Report timeline comments from TR apartments into cleaning_reports.
     *
     * @return array{apartments:int, comments:int, upserted:int, failed:int}
     */
    public function sync(?int $onlyApartmentId = null): array
    {
        $domain = $this->oauth->normalizeDomain((string) config('services.b24_tr.portal_domain'));
        if ($domain === '') {
            throw new RuntimeException('TR portal domain is not configured.');
        }

        $entityTypeId = (int) config('services.cleaning.apartment_entity_type_id');
        if ($entityTypeId <= 0) {
            throw new RuntimeException('Cleaning apartment entity type is not configured.');
        }

        $apartments = $onlyApartmentId !== null && $onlyApartmentId > 0
            ? [['id' => $onlyApartmentId, 'title' => '']]
            : $this->fetchApartments($domain, $entityTypeId);

        $stats = [
            'apartments' => count($apartments),
            'comments' => 0,
            'upserted' => 0,
            'failed' => 0,
        ];

        foreach ($apartments as $apartment) {
            $apartmentId = (int) ($apartment['id'] ?? 0);
            if ($apartmentId <= 0) {
                continue;
            }

            try {
                $comments = $this->fetchComments($domain, $entityTypeId, $apartmentId);
            } catch (Throwable $e) {
                $stats['failed']++;
                Log::channel('bitrix_cleaning')->warning('CLEANING_HISTORY_SYNC_APARTMENT_FAILED', [
                    'apartment_id' => $apartmentId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $apartmentTitle = trim((string) ($apartment['title'] ?? ''));
            foreach ($comments as $comment) {
                $stats['comments']++;
                $text = (string) ($comment['COMMENT'] ?? '');
                if (! preg_match('/Cleaning Report/i', $text)) {
                    continue;
                }

                $commentId = (int) ($comment['ID'] ?? 0);
                if ($commentId <= 0) {
                    continue;
                }

                try {
                    $reportedAt = $this->parseCreated((string) ($comment['CREATED'] ?? ''));
                    CleaningReport::query()->updateOrCreate(
                        ['apartment_comment_id' => $commentId],
                        [
                            'apartment_bitrix_id' => $apartmentId,
                            'apartment_title' => $apartmentTitle !== ''
                                ? mb_substr($apartmentTitle, 0, 255)
                                : $this->extractField($text, 'Apartment'),
                            'unit_bitrix_id' => null,
                            'unit_title' => mb_substr($this->extractField($text, 'Unit'), 0, 255) ?: null,
                            'cleaning_type' => mb_substr($this->extractField($text, 'Cleaning type'), 0, 100) ?: null,
                            'report_date' => mb_substr($this->extractField($text, 'Date'), 0, 32) ?: null,
                            'report_time' => mb_substr($this->extractField($text, 'Time'), 0, 16) ?: null,
                            'staff' => mb_substr($this->extractField($text, 'Cleaning staff'), 0, 100) ?: null,
                            'needs_repair' => $this->isYes($this->extractField($text, 'Needs repair')),
                            'notes' => $this->extractField($text, 'Notes') ?: null,
                            'repair_task_id' => $this->extractTaskId($text, 'Repair task'),
                            'photo_previews' => $this->photoPreviews($comment['FILES'] ?? null),
                            'reported_at' => $reportedAt,
                        ]
                    );
                    $stats['upserted']++;
                } catch (Throwable $e) {
                    $stats['failed']++;
                    Log::channel('bitrix_cleaning')->warning('CLEANING_HISTORY_SYNC_COMMENT_FAILED', [
                        'apartment_id' => $apartmentId,
                        'comment_id' => $commentId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::channel('bitrix_cleaning')->info('CLEANING_HISTORY_SYNC_OK', $stats);

        return $stats;
    }

    /**
     * @return list<array{id:int, title:string}>
     */
    private function fetchApartments(string $domain, int $entityTypeId): array
    {
        $start = 0;
        $items = [];

        while (true) {
            $response = $this->bitrixRest->postJson('crm.item.list', [
                'entityTypeId' => $entityTypeId,
                'select' => ['id', 'title'],
                'order' => ['id' => 'ASC'],
                'start' => $start,
            ], $domain);

            $batch = data_get($response, 'result.items', []);
            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $items[] = [
                    'id' => $id,
                    'title' => trim((string) ($item['title'] ?? '')),
                ];
            }

            $next = data_get($response, 'result.next', data_get($response, 'next'));
            if (! is_numeric($next)) {
                break;
            }
            $start = (int) $next;
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchComments(string $domain, int $entityTypeId, int $apartmentId): array
    {
        $start = 0;
        $items = [];

        while (true) {
            $response = $this->bitrixRest->postJson('crm.timeline.comment.list', [
                'filter' => [
                    'ENTITY_ID' => $apartmentId,
                    'ENTITY_TYPE' => 'dynamic_'.$entityTypeId,
                ],
                'order' => ['ID' => 'DESC'],
                'select' => ['ID', 'CREATED', 'COMMENT', 'AUTHOR_ID', 'FILES'],
                'start' => $start,
            ], $domain);

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

    private function extractField(string $comment, string $label): string
    {
        $plain = $this->stripBbCode($comment);
        $pattern = '/(?:^|\n)\s*'.preg_quote($label, '/').'\s*:\s*(.+?)(?=\n|$)/iu';
        if (preg_match($pattern, $plain, $m) !== 1) {
            return '';
        }

        return trim((string) $m[1]);
    }

    private function stripBbCode(string $text): string
    {
        $text = preg_replace('/\[url=[^\]]*\](.*?)\[\/url\]/iu', '$1', $text) ?? $text;
        $text = preg_replace('/\[\/?(?:b|i|u|color|size|font|img)[^\]]*\]/iu', '', $text) ?? $text;
        $text = preg_replace('/\[\/?[^\]]+\]/u', '', $text) ?? $text;

        return trim(str_replace("\r", '', $text));
    }

    private function isYes(string $value): bool
    {
        $value = strtolower(trim($value));

        return in_array($value, ['yes', 'y', 'true', '1'], true);
    }

    private function extractTaskId(string $comment, string $label): ?int
    {
        $value = $this->extractField($comment, $label);
        if (preg_match('/(\d+)/', $value, $m) !== 1) {
            return null;
        }
        $id = (int) $m[1];

        return $id > 0 ? $id : null;
    }

    private function parseCreated(string $created): Carbon
    {
        try {
            return Carbon::parse($created);
        } catch (Throwable) {
            return now();
        }
    }

    /**
     * @return list<array{id:int, name:string}>
     */
    private function photoPreviews(mixed $files): array
    {
        if (! is_array($files) || $files === []) {
            return [];
        }

        $photos = [];
        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }
            $id = (int) ($file['id'] ?? $file['ID'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $name = trim((string) ($file['name'] ?? $file['NAME'] ?? 'photo'));
            $photos[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : 'photo',
            ];
        }

        return $photos;
    }
}
