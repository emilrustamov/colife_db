<?php

namespace App\Services;

use App\Models\BitrixUnitSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class BitrixUnitsSyncService
{
    private const STAGE_FOR_RENT = 'DT167_12:PREPARATION';

    private const SHARING_ENUM_ID = 242;

    /**
     * Fetch Bitrix CRM items and upsert local snapshot rows.
     */
    public function sync(): void
    {
        $start = 0;
        $limit = 50;

        do {
            $response = Http::timeout(60)
                ->asForm()
                ->post($this->buildUrl('crm.item.list.json'), [
                    'entityTypeId' => config('services.bitrix_units.entity_type_id'),
                    'select' => [
                        'id',
                        'stageId',
                        'UF_CRM_8_1684429208',
                        'UF_CRM_8_IS_BOOKED',
                        'UF_CRM_8_MOVED_FROM_TERMINATION_YESTERDAY',
                        'UF_CRM_8_IS_STAGE_EQUAL_TO_STATUS',
                        'UF_CRM_8_1682957076924',
                        'UF_CRM_8_1738748217262',
                    ],
                    'start' => $start,
                    'limit' => $limit,
                ]);

            $response->throw();

            $data = $response->json();

            $items = data_get($data, 'result.items', []);
            foreach ($items as $item) {
                $normalized = $this->normalizeItem($item);

                BitrixUnitSnapshot::updateOrCreate(
                    ['unit_id' => $normalized['unit_id']],
                    $normalized
                );
            }

            $next = data_get($data, 'next');
            $start = is_numeric($next) ? (int) $next : null;

        } while ($start !== null);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item): array
    {
        $apartIds = $item['UF_CRM_8_1684429208'] ?? [];
        $sharingValue = $item['UF_CRM_8_1682957076924'] ?? null;
        $stage = (string) ($item['stageId'] ?? '');

        $isBooked = $this->toBitrixBool($item['UF_CRM_8_IS_BOOKED'] ?? null);
        $isMovedFromTermination = $this->toBitrixBool($item['UF_CRM_8_MOVED_FROM_TERMINATION_YESTERDAY'] ?? null);
        $isStageStatus = $this->toBitrixBool($item['UF_CRM_8_IS_STAGE_EQUAL_TO_STATUS'] ?? null);
        $isSharing = $this->isSharing($sharingValue);
        $checkInDate = $this->parseDate($item['UF_CRM_8_1738748217262'] ?? null);

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
            'synced_at' => now(),
        ];
    }

    /**
     * @param  mixed  $value
     * @return int|null
     */
    private function normalizeApartId(mixed $value): ?int
    {
        if (empty($value)) {
            return null;
        }

        if (is_array($value)) {
            foreach ($value as $v) {
                $normalized = $this->normalizeSingleId($v);
                if ($normalized !== null) {
                    return $normalized;
                }
            }

            return null;
        }

        return $this->normalizeSingleId($value);
    }

    /**
     * @param  mixed  $value
     * @return int|null
     */
    private function normalizeSingleId(mixed $value): ?int
    {
        if (empty($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            if (is_numeric($trimmed)) {
                return (int) $trimmed;
            }

            if (preg_match('/\d+/', $trimmed, $matches)) {
                return (int) $matches[0];
            }
        }

        if (is_array($value) && ! empty($value)) {
            foreach ($value as $v) {
                $normalized = $this->normalizeSingleId($v);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return null;
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
