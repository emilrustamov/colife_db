<?php

namespace App\Services\Profiles;

use App\Models\Unit;
use App\Support\BitrixValue;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UnitProfile extends SpaProfile
{
    public const ENTITY_TYPE_ID = 167;

    public function entity(): string
    {
        return 'units';
    }

    protected function modelClass(): string
    {
        return Unit::class;
    }

    protected function typeId(): int
    {
        return self::ENTITY_TYPE_ID;
    }

    protected function eventBase(): string
    {
        return 'bitrix.unit';
    }

    protected function stageType(): string
    {
        return 'unit';
    }

    /**
     * @return list<string>
     */
    protected function updateCols(): array
    {
        return [
            'apartment_id',
            'title',
            'stage_id',
            'internal_number',
            'is_deleted',
            'bitrix_created_at',
            'bitrix_updated_at',
            'last_synced_at',
            'updated_at',
        ];
    }

    /**
     * @return list<string>
     */
    protected function logKeys(): array
    {
        return [
            'bitrix_id',
            'apartment_id',
            'title',
            'stage_id',
            'internal_number',
            'is_deleted',
        ];
    }

    /**
     * @return array{stageIdMap: array<string, int>, apartmentIdByBitrixId: array<int, string>}
     */
    protected function context(): array
    {
        return [
            'stageIdMap' => $this->loadStageIdMap(),
            'apartmentIdByBitrixId' => DB::table('apartments')
                ->pluck('id', 'bitrix_id')
                ->mapWithKeys(static fn ($id, $bitrixId): array => [(int) $bitrixId => (string) $id])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function normalizeItem(array $item, mixed $existingId, array $context, Carbon $now): array
    {
        $bitrixId = (int) ($item['id'] ?? 0);
        if ($bitrixId <= 0) {
            throw new \RuntimeException('Invalid unit ID');
        }

        $apartBitrixId = BitrixValue::firstInt($item['ufCrm8_1684429208'] ?? null);
        if ($apartBitrixId === null) {
            throw new \RuntimeException('Apartment link is empty');
        }

        /** @var array<int, string> $apartmentIdByBitrixId */
        $apartmentIdByBitrixId = $context['apartmentIdByBitrixId'];
        $apartmentId = $apartmentIdByBitrixId[$apartBitrixId] ?? null;
        if (! is_string($apartmentId) || $apartmentId === '') {
            throw new \RuntimeException('Apartment not found locally');
        }

        /** @var array<string, int> $stageIdMap */
        $stageIdMap = $context['stageIdMap'];
        $stageCode = trim((string) ($item['stageId'] ?? ''));
        $internalNumber = BitrixValue::firstStr(
            $item['ufCrm8_1698838056004'] ?? null,
            $item['ufCrm6_1707234311507'] ?? null
        );

        return [
            'id' => $existingId ?? (string) Str::uuid(),
            'bitrix_id' => $bitrixId,
            'apartment_id' => $apartmentId,
            'title' => BitrixValue::str($item['title'] ?? null),
            'stage_id' => $stageCode !== '' ? ($stageIdMap[$stageCode] ?? null) : null,
            'internal_number' => $internalNumber,
            'is_deleted' => false,
            'bitrix_created_at' => BitrixValue::dt($item['createdTime'] ?? null),
            'bitrix_updated_at' => BitrixValue::dt($item['updatedTime'] ?? null),
            'last_synced_at' => $now,
            'updated_at' => $now,
            'created_at' => $now,
        ];
    }
}
