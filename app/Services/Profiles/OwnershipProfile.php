<?php

namespace App\Services\Profiles;

use App\Models\ApartmentOwnership;
use App\Support\BitrixEnums;
use App\Support\BitrixValue;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OwnershipProfile extends SpaProfile
{
    public const ENTITY_TYPE_ID = 148;

    /**
     * @var array<string, array<string, string>>|null
     */
    private ?array $enumMap = null;

    public function entity(): string
    {
        return 'apartment_ownerships';
    }

    protected function modelClass(): string
    {
        return ApartmentOwnership::class;
    }

    protected function typeId(): int
    {
        return self::ENTITY_TYPE_ID;
    }

    protected function eventBase(): string
    {
        return 'bitrix.apartment_ownership';
    }

    protected function stageType(): string
    {
        return 'apartment_ownership';
    }

    /**
     * @return list<string>
     */
    protected function updateCols(): array
    {
        return [
            'title',
            'stage_id',
            'apartment_id',
            'contract_start_date',
            'contract_end_date',
            'pml_start_date',
            'pml_end_date',
            'dtcm_start_date',
            'dtcm_end_date',
            'termination_date',
            'termination_reason',
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
            'title',
            'stage_id',
            'apartment_id',
            'contract_start_date',
            'contract_end_date',
            'pml_start_date',
            'pml_end_date',
            'dtcm_start_date',
            'dtcm_end_date',
            'termination_date',
            'termination_reason',
            'is_deleted',
        ];
    }

    /**
     * @return array{
     *     stageIdMap: array<string, int>,
     *     apartmentIdByBitrixId: array<int, string>,
     *     enumMap: array<string, array<string, string>>
     * }
     */
    protected function context(): array
    {
        return [
            'stageIdMap' => $this->loadStageIdMap(),
            'apartmentIdByBitrixId' => DB::table('apartments')
                ->pluck('id', 'bitrix_id')
                ->mapWithKeys(static fn ($id, $bitrixId): array => [(int) $bitrixId => (string) $id])
                ->all(),
            'enumMap' => $this->loadEnumMap(),
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
            throw new \RuntimeException('Invalid apartment ownership ID');
        }

        $apartmentBitrixId = BitrixValue::firstInt($item['parentId144'] ?? null)
            ?? BitrixValue::firstInt($item['ufCrm10_1693823301'] ?? null);
        if ($apartmentBitrixId === null) {
            throw new \RuntimeException('Apartment link is empty');
        }

        /** @var array<int, string> $apartmentIdByBitrixId */
        $apartmentIdByBitrixId = $context['apartmentIdByBitrixId'];
        $apartmentId = $apartmentIdByBitrixId[$apartmentBitrixId] ?? null;
        if (! is_string($apartmentId) || $apartmentId === '') {
            throw new \RuntimeException('Apartment not found locally');
        }

        /** @var array<string, int> $stageIdMap */
        $stageIdMap = $context['stageIdMap'];
        /** @var array<string, array<string, string>> $enumMap */
        $enumMap = $context['enumMap'];
        $stageCode = trim((string) ($item['stageId'] ?? ''));

        return [
            'id' => $existingId ?? (string) Str::uuid(),
            'bitrix_id' => $bitrixId,
            'title' => BitrixValue::str($item['title'] ?? null),
            'stage_id' => $stageCode !== '' ? ($stageIdMap[$stageCode] ?? null) : null,
            'apartment_id' => $apartmentId,
            'contract_start_date' => BitrixValue::date($item['ufCrm10_1693823247516'] ?? null),
            'contract_end_date' => BitrixValue::date($item['ufCrm10_1693823282826'] ?? null),
            'pml_start_date' => BitrixValue::date($item['ufCrm10_1708956056'] ?? null),
            'pml_end_date' => BitrixValue::date($item['ufCrm10_1708955996'] ?? null),
            'dtcm_start_date' => BitrixValue::date($item['ufCrm10_1714652654'] ?? null),
            'dtcm_end_date' => BitrixValue::date($item['ufCrm10_1714652687'] ?? null),
            'termination_date' => BitrixValue::date($item['ufCrm10TerminationDate'] ?? null),
            'termination_reason' => BitrixEnums::resolve($enumMap, 'ufCrm10_1727422412', $item['ufCrm10_1727422412'] ?? null),
            'is_deleted' => false,
            'bitrix_created_at' => BitrixValue::dt($item['createdTime'] ?? null),
            'bitrix_updated_at' => BitrixValue::dt($item['updatedTime'] ?? null),
            'last_synced_at' => $now,
            'updated_at' => $now,
            'created_at' => $now,
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function loadEnumMap(): array
    {
        if ($this->enumMap !== null) {
            return $this->enumMap;
        }

        $this->enumMap = BitrixEnums::load(
            $this->bitrixRestClient,
            self::ENTITY_TYPE_ID,
            true
        );

        return $this->enumMap;
    }
}
