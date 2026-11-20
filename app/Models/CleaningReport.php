<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CleaningReport extends Model
{
    protected $table = 'cleaning_reports';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'apartment_bitrix_id',
        'apartment_title',
        'unit_bitrix_id',
        'unit_title',
        'cleaning_type',
        'report_date',
        'report_time',
        'staff',
        'submitted_by_user_id',
        'submitted_by_name',
        'needs_repair',
        'notes',
        'apartment_comment_id',
        'unit_comment_id',
        'repair_task_id',
        'photo_previews',
        'reported_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'needs_repair' => 'boolean',
            'photo_previews' => 'array',
            'reported_at' => 'datetime',
        ];
    }

    /**
     * Paginated reports for the History tab (optional apartment / text filter).
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public static function historyPayload(
        ?int $apartmentBitrixId = null,
        int $page = 1,
        int $perPage = 20,
        string $search = '',
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $search = trim($search);

        $query = static::query()->orderByDesc('reported_at')->orderByDesc('id');
        if ($apartmentBitrixId !== null && $apartmentBitrixId > 0) {
            $query->where('apartment_bitrix_id', $apartmentBitrixId);
        }
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($builder) use ($like): void {
                $builder
                    ->where('apartment_title', 'like', $like)
                    ->orWhere('unit_title', 'like', $like)
                    ->orWhere('cleaning_type', 'like', $like)
                    ->orWhere('staff', 'like', $like)
                    ->orWhere('notes', 'like', $like)
                    ->orWhere('report_date', 'like', $like)
                    ->orWhere('report_time', 'like', $like);
            });
        }

        $total = (int) (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($page > $lastPage) {
            $page = $lastPage;
        }

        $items = $query
            ->forPage($page, $perPage)
            ->get()
            ->map(static function (self $row): array {
                return [
                    'id' => (int) $row->id,
                    'apartment_id' => (int) $row->apartment_bitrix_id,
                    'apartment_title' => (string) ($row->apartment_title ?: ('#'.$row->apartment_bitrix_id)),
                    'createdLabel' => self::formatReportedAt($row->reported_at),
                    'type' => (string) ($row->cleaning_type ?: 'Cleaning Report'),
                    'unit' => (string) ($row->unit_title ?: ''),
                    'date' => (string) ($row->report_date ?: ''),
                    'time' => (string) ($row->report_time ?: ''),
                    'staff' => (string) ($row->staff ?: ''),
                    'submitted_by' => (string) ($row->submitted_by_name ?: ''),
                    'submitted_by_user_id' => (int) ($row->submitted_by_user_id ?: 0),
                    'notes' => (string) ($row->notes ?: ''),
                    'needsRepair' => (bool) $row->needs_repair,
                    'photos' => self::normalizePhotos($row->photo_previews ?? []),
                ];
            })
            ->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ];
    }

    private static function formatReportedAt(mixed $reportedAt): string
    {
        if ($reportedAt === null || $reportedAt === '') {
            return '';
        }

        try {
            $tz = (string) config('services.cleaning.display_timezone', 'Asia/Tashkent');
            if ($tz === '') {
                $tz = 'Asia/Tashkent';
            }

            $dt = $reportedAt instanceof \Carbon\CarbonInterface
                ? $reportedAt->copy()
                : \Carbon\Carbon::parse((string) $reportedAt, 'UTC');

            // Stored as UTC; show in local display timezone.
            return $dt->timezone($tz)->format('d.m.Y H:i');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param  mixed  $raw
     * @return list<array{id:int|null, name:string, url:string, openUrl:string}>
     */
    private static function normalizePhotos(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $photos = [];
        foreach ($raw as $photo) {
            if (! is_array($photo)) {
                continue;
            }

            $id = (int) ($photo['id'] ?? $photo['ID'] ?? 0);
            $name = trim((string) ($photo['name'] ?? $photo['NAME'] ?? 'photo'));
            if ($name === '') {
                $name = 'photo';
            }

            if ($id > 0) {
                $proxy = url('/api/cleaning/file/'.$id);
                $photos[] = [
                    'id' => $id,
                    'name' => $name,
                    'url' => $proxy,
                    'openUrl' => $proxy.'?redirect=1',
                ];

                continue;
            }

            $url = html_entity_decode(trim((string) ($photo['url'] ?? '')), ENT_QUOTES | ENT_HTML5);
            if ($url === '') {
                continue;
            }
            $openUrl = html_entity_decode(trim((string) ($photo['openUrl'] ?? $url)), ENT_QUOTES | ENT_HTML5);
            $photos[] = [
                'id' => null,
                'name' => $name,
                'url' => $url,
                'openUrl' => $openUrl !== '' ? $openUrl : $url,
            ];
        }

        return $photos;
    }
}
