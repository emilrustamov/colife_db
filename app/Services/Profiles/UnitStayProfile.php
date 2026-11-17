<?php

namespace App\Services\Profiles;

use App\Models\UnitStay;
use App\Support\BitrixEnums;
use App\Support\BitrixValue;
use App\Support\EntityMap;
use Carbon\Carbon;
use Illuminate\Support\Str;

class UnitStayProfile extends SpaProfile
{
    public const ENTITY_TYPE_ID = 183;

    /**
     * @return list<string>
     */
    public static function select(): array
    {
        return [
            'id', 'title', 'stageId', 'createdTime', 'updatedTime', 'contactId',
            'opportunity', 'currencyId', 'parentId2', 'parentId167',
            'ufCrm20_1693919019', 'ufCrm20_1700215654', 'ufCrm20Deal',
            'ufCrm20_1693561495', 'ufCrm20TypeOfDeal', 'ufCrm20TypeOfPayment',
            'ufCrm20_1744800159', 'ufCrm20_1744800193', 'ufCrm20HowManyMonths',
            'ufCrm20RentalPrice', 'ufCrm20Deposit', 'ufCrm20TotalContractAmount',
            'ufCrm20_1696523391', 'ufCrm20ContractStartDate', 'ufCrm20ContractEndDate',
        ];
    }

    public function entity(): string
    {
        return 'unit_stays';
    }

    protected function model(): string
    {
        return UnitStay::class;
    }

    protected function typeId(): int
    {
        $typeId = EntityMap::typeId('ae', 'unit_stays');

        return $typeId > 0 ? $typeId : self::ENTITY_TYPE_ID;
    }

    protected function event(): string
    {
        return 'bitrix.unit_stay';
    }

    protected function stageEntity(): string
    {
        return 'unit_stay';
    }

    /**
     * @return list<string>
     */
    protected function fields(): array
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
        ];
    }

    /**
     * @return array{
     *     stages: array<string, int>,
     *     units: array<int, string>,
     *     contacts: array<int, string>,
     *     enums: array<string, array<string, string>>
     * }
     */
    protected function context(): array
    {
        return [
            'stages' => $this->stageMap(),
            'units' => $this->idMap('units', true),
            'contacts' => $this->idMap('contacts'),
            'enums' => $this->loadEnums(true, self::ENTITY_TYPE_ID),
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

        /** @var array<int, string> $units */
        $units = $context['units'];
        $unitId = $this->requireMappedId(
            $units,
            BitrixValue::firstInt($item['parentId167'] ?? null)
                ?? BitrixValue::firstInt($item['ufCrm20_1693919019'] ?? null),
            'Unit link is empty',
            'Unit not found locally'
        );

        /** @var array<int, string> $contacts */
        $contacts = $context['contacts'];
        /** @var array<string, int> $stages */
        $stages = $context['stages'];
        /** @var array<string, array<string, string>> $enums */
        $enums = $context['enums'];

        $tenantId = BitrixValue::firstInt($item['contactId'] ?? null);
        $coTenantId = BitrixValue::firstInt($item['ufCrm20_1700215654'] ?? null);
        $stageCode = trim((string) ($item['stageId'] ?? ''));

        return [
            'id' => $existingId ?? (string) Str::uuid(),
            'portal_id' => $this->portalId(),
            'bitrix_id' => $bitrixId,
            'title' => BitrixValue::str($item['title'] ?? null),
            'stage_id' => $stageCode !== '' ? ($stages[$stageCode] ?? null) : null,
            'unit_id' => $unitId,
            'tenant_contact_id' => $tenantId !== null ? ($contacts[$tenantId] ?? null) : null,
            'co_tenant_contact_id' => $coTenantId !== null ? ($contacts[$coTenantId] ?? null) : null,
            'deal_id' => BitrixValue::firstInt($item['ufCrm20Deal'] ?? null)
                ?? BitrixValue::firstInt($item['parentId2'] ?? null),
            'contract_type' => BitrixEnums::resolve($enums, 'ufCrm20_1693561495', $item['ufCrm20_1693561495'] ?? null),
            'type_of_deal' => BitrixEnums::resolve($enums, 'ufCrm20TypeOfDeal', $item['ufCrm20TypeOfDeal'] ?? null),
            'type_of_payment' => BitrixEnums::resolve($enums, 'ufCrm20TypeOfPayment', $item['ufCrm20TypeOfPayment'] ?? null),
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
            ...$this->stamps($item, $now),
        ];
    }
}
