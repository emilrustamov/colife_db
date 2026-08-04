<?php

namespace App\Services\Profiles;

use App\Models\UnitStay;
use App\Support\BitrixEnums;
use App\Support\BitrixValue;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UnitStayProfile extends SpaProfile
{
    public const ENTITY_TYPE_ID = 183;

    /**
     * @var array<string, array<string, string>>|null
     */
    private ?array $enumMap = null;

    public function entity(): string
    {
        return 'unit_stays';
    }

    protected function modelClass(): string
    {
        return UnitStay::class;
    }

    protected function typeId(): int
    {
        return self::ENTITY_TYPE_ID;
    }

    protected function eventBase(): string
    {
        return 'bitrix.unit_stay';
    }

    protected function stageType(): string
    {
        return 'unit_stay';
    }

    /**
     * @return list<string>
     */
    protected function updateCols(): array
    {
        return [
            'title',
            'stage_id',
            'unit_id',
            'tenant_contact_id',
            'co_tenant_contact_id',
            'deal_id',
            'contract_type',
            'type_of_deal',
            'type_of_payment',
            'contract_start_date',
            'contract_end_date',
            'months_of_stay',
            'rental_price',
            'deposit',
            'total_contract_amount',
            'opportunity',
            'currency_id',
            'passport_number',
            'check_in_date',
            'check_out_date',
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
            'unit_id',
            'tenant_contact_id',
            'co_tenant_contact_id',
            'deal_id',
            'contract_type',
            'type_of_deal',
            'type_of_payment',
            'contract_start_date',
            'contract_end_date',
            'months_of_stay',
            'rental_price',
            'deposit',
            'total_contract_amount',
            'opportunity',
            'currency_id',
            'passport_number',
            'check_in_date',
            'check_out_date',
            'is_deleted',
        ];
    }

    /**
     * @return array{
     *     stageIdMap: array<string, int>,
     *     unitIdByBitrixId: array<int, string>,
     *     contactIdByBitrixId: array<int, string>,
     *     enumMap: array<string, array<string, string>>
     * }
     */
    protected function context(): array
    {
        return [
            'stageIdMap' => $this->loadStageIdMap(),
            'unitIdByBitrixId' => DB::table('units')
                ->pluck('id', 'bitrix_id')
                ->mapWithKeys(static fn ($id, $bitrixId): array => [(int) $bitrixId => (string) $id])
                ->all(),
            'contactIdByBitrixId' => DB::table('contacts')
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
            throw new \RuntimeException('Invalid unit stay ID');
        }

        $unitBitrixId = BitrixValue::firstInt($item['parentId167'] ?? null)
            ?? BitrixValue::firstInt($item['ufCrm20_1693919019'] ?? null);
        if ($unitBitrixId === null) {
            throw new \RuntimeException('Unit link is empty');
        }

        /** @var array<int, string> $unitIdByBitrixId */
        $unitIdByBitrixId = $context['unitIdByBitrixId'];
        $unitId = $unitIdByBitrixId[$unitBitrixId] ?? null;
        if (! is_string($unitId) || $unitId === '') {
            throw new \RuntimeException('Unit not found locally');
        }

        /** @var array<int, string> $contactIdByBitrixId */
        $contactIdByBitrixId = $context['contactIdByBitrixId'];
        /** @var array<string, int> $stageIdMap */
        $stageIdMap = $context['stageIdMap'];
        /** @var array<string, array<string, string>> $enumMap */
        $enumMap = $context['enumMap'];

        $tenantBitrixId = BitrixValue::firstInt($item['contactId'] ?? null);
        $coTenantBitrixId = BitrixValue::firstInt($item['ufCrm20_1700215654'] ?? null);
        $stageCode = trim((string) ($item['stageId'] ?? ''));

        return [
            'id' => $existingId ?? (string) Str::uuid(),
            'bitrix_id' => $bitrixId,
            'title' => BitrixValue::str($item['title'] ?? null),
            'stage_id' => $stageCode !== '' ? ($stageIdMap[$stageCode] ?? null) : null,
            'unit_id' => $unitId,
            'tenant_contact_id' => $tenantBitrixId !== null ? ($contactIdByBitrixId[$tenantBitrixId] ?? null) : null,
            'co_tenant_contact_id' => $coTenantBitrixId !== null ? ($contactIdByBitrixId[$coTenantBitrixId] ?? null) : null,
            'deal_id' => BitrixValue::firstInt($item['ufCrm20Deal'] ?? null)
                ?? BitrixValue::firstInt($item['parentId2'] ?? null),
            'contract_type' => BitrixEnums::resolve($enumMap, 'ufCrm20_1693561495', $item['ufCrm20_1693561495'] ?? null),
            'type_of_deal' => BitrixEnums::resolve($enumMap, 'ufCrm20TypeOfDeal', $item['ufCrm20TypeOfDeal'] ?? null),
            'type_of_payment' => BitrixEnums::resolve($enumMap, 'ufCrm20TypeOfPayment', $item['ufCrm20TypeOfPayment'] ?? null),
            'contract_start_date' => BitrixValue::date($item['ufCrm20_1744800159'] ?? null),
            'contract_end_date' => BitrixValue::date($item['ufCrm20_1744800193'] ?? null),
            'months_of_stay' => BitrixValue::uint($item['ufCrm20HowManyMonths'] ?? null),
            'rental_price' => BitrixValue::money($item['ufCrm20RentalPrice'] ?? null),
            'deposit' => BitrixValue::money($item['ufCrm20Deposit'] ?? null),
            'total_contract_amount' => BitrixValue::money($item['ufCrm20TotalContractAmount'] ?? null),
            'opportunity' => BitrixValue::money($item['opportunity'] ?? null),
            'currency_id' => BitrixValue::str($item['currencyId'] ?? null),
            'passport_number' => BitrixValue::str($item['ufCrm20_1696523391'] ?? null),
            'check_in_date' => BitrixValue::date($item['ufCrm20ContractStartDate'] ?? null),
            'check_out_date' => BitrixValue::date($item['ufCrm20ContractEndDate'] ?? null),
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
