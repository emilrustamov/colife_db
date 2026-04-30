<?php

namespace App\Services;

use App\Models\BitrixUnitsSnapshot;
use Carbon\Carbon;
use Throwable;
use Illuminate\Support\Facades\Http;

class BitrixUnitsSyncService
{
    private const STAGE_FOR_RENT = 'DT167_12:PREPARATION';

    private const SHARING_ENUM_ID = 242;

    /**
     * Fetch Bitrix CRM items and upsert local snapshot rows.
     */
    /**
     * @return array{total:int, successful:int, failed:int, failed_unit_ids:list<int|string>}
     */
    public function sync(): array
    {
        $start = 0;
        $now = now();
        $total = 0;
        $successful = 0;
        $failedUnitIds = [];

        while (true) {
            $response = Http::timeout(60)
                ->acceptJson()
                ->asJson()
                ->post($this->buildUrl('crm.item.list.json'), [
                    'entityTypeId' => (int) config('services.bitrix_units.entity_type_id'),
                    'select' => [
                        'id',
                        'stageId',
                        'ufCrm8_1684429208',
                        'ufCrm8IsBooked',
                        'ufCrm8MovedFromTerminationYesterday',
                        'ufCrm8IsStageEqualToStatus',
                        'ufCrm8_1682957076924',
                        'ufCrm8_1738748217262',
                    ],
                    'start' => $start,
                ]);

            $response->throw();

            $data = $response->json();
            $rows = [];

            foreach (data_get($data, 'result.items', []) as $item) {
                $total++;

                try {
                    $rows[] = $this->normalizeItem($item, $now);
                    $successful++;
                } catch (Throwable) {
                    $failedUnitIds[] = $item['id'] ?? 'unknown';
                }
            }

            if ($rows !== []) {
                BitrixUnitsSnapshot::upsert(
                    $rows,
                    ['unit_id'],
                    [
                        'apart_id',
                        'is_booked',
                        'is_moved_from_termination',
                        'is_stage_status',
                        'stage',
                        'is_sharing',
                        'check_in_date',
                        'is_idle',
                        'synced_at',
                        'updated_at',
                    ]
                );
            }

            $next = data_get($data, 'next');
            if (! is_numeric($next)) {
                break;
            }

            $start = (int) $next;
        }

        return [
            'total' => $total,
            'successful' => $successful,
            'failed' => count($failedUnitIds),
            'failed_unit_ids' => $failedUnitIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Carbon  $syncedAt
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item, Carbon $syncedAt): array
    {
        $apartIds = $item['ufCrm8_1684429208'] ?? [];
        $sharingValue = $item['ufCrm8_1682957076924'] ?? null;
        $stage = (string) ($item['stageId'] ?? '');

        $isBooked = $this->toBitrixBool($item['ufCrm8IsBooked'] ?? null);
        $isMovedFromTermination = $this->toBitrixBool($item['ufCrm8MovedFromTerminationYesterday'] ?? null);
        $isStageStatus = $this->toBitrixBool($item['ufCrm8IsStageEqualToStatus'] ?? null);
        $isSharing = $this->isSharing($sharingValue);
        $checkInDate = $this->parseDate($item['ufCrm8_1738748217262'] ?? null);

        $isIdle = $this->calculateIdle(
            stage: $stage,
            isBooked: $isBooked,
            isMovedFromTermination: $isMovedFromTermination,
            isStageStatus: $isStageStatus,
            isSharing: $isSharing,
            checkInDate: $checkInDate
        );

        return [
            'unit_id' => (int) $item['id'],
            'apart_id' => $this->normalizeApartId($apartIds),
            'is_booked' => $isBooked,
            'is_moved_from_termination' => $isMovedFromTermination,
            'is_stage_status' => $isStageStatus,
            'stage' => $stage,
            'is_sharing' => $isSharing,
            'check_in_date' => $checkInDate,
            'is_idle' => $isIdle,
            'synced_at' => $syncedAt,
            'updated_at' => $syncedAt,
            'created_at' => $syncedAt,
        ];
    }

    /**
     * @param  mixed  $value
     * @return int|null
     */
    private function normalizeApartId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_array($value)) {
            $first = reset($value);

            return is_numeric($first) ? (int) $first : null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function calculateIdle(
        string $stage,
        bool $isBooked,
        bool $isMovedFromTermination,
        bool $isStageStatus,
        bool $isSharing,
        ?Carbon $checkInDate
    ): bool {
        if ($stage !== self::STAGE_FOR_RENT) {
            return false;
        }

        if (! $isStageStatus) {
            return false;
        }

        if ($isMovedFromTermination) {
            return false;
        }

        if ($isBooked) {
            if ($isSharing) {
                return false;
            }

            if ($checkInDate) {
                $todayEnd = now()->endOfDay();
                $fourDaysLaterEnd = now()->addDays(4)->endOfDay();

                if ($checkInDate->between($todayEnd, $fourDaysLaterEnd) || $checkInDate->lte($fourDaysLaterEnd)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function toBitrixBool(mixed $value): bool
    {
        return in_array((string) $value, ['Yes', 'Y', '1', 'true'], true);
    }

    private function isSharing(mixed $value): bool
    {
        if (is_array($value)) {
            return in_array((string) self::SHARING_ENUM_ID, array_map('strval', $value), true);
        }

        return (string) $value === (string) self::SHARING_ENUM_ID;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        return Carbon::parse($value);
    }

    private function buildUrl(string $method): string
    {
        return rtrim((string) config('services.bitrix_units.webhook'), '/').'/'.$method;
    }
}
