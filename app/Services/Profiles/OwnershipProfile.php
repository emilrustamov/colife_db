<?php

namespace App\Services\Profiles;

use App\Models\ApartmentOwnership;
use App\Support\BitrixEnums;
use App\Support\BitrixValue;
use App\Support\EntityMap;
use Carbon\Carbon;
use Illuminate\Support\Str;

class OwnershipProfile extends SpaProfile
{
    public const ENTITY_TYPE_ID = 148;

    /**
     * @return list<string>
     */
    public static function select(): array
    {
        return [
            'id', 'title', 'stageId', 'createdTime', 'updatedTime', 'parentId144',
            'ufCrm10_1693823301', 'ufCrm10_1693823247516', 'ufCrm10_1693823282826',
            'ufCrm10_1708956056', 'ufCrm10_1708955996', 'ufCrm10_1714652654',
            'ufCrm10_1714652687', 'ufCrm10TerminationDate', 'ufCrm10_1727422412',
        ];
    }

    public function entity(): string
    {
        return 'apartment_ownerships';
    }

    protected function model(): string
    {
        return ApartmentOwnership::class;
    }

    protected function typeId(): int
    {
        $typeId = EntityMap::typeId('ae', 'apartment_ownerships');

        return $typeId > 0 ? $typeId : self::ENTITY_TYPE_ID;
    }

    protected function event(): string
    {
        return 'bitrix.apartment_ownership';
    }

    protected function stageEntity(): string
    {
        return 'apartment_ownership';
    }

    /**
     * @return list<string>
     */
    protected function fields(): array
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
        ];
    }

    /**
     * @return array{
     *     stages: array<string, int>,
     *     apartments: array<int, string>,
     *     enums: array<string, array<string, string>>
     * }
     */
    protected function context(): array
    {
        return [
            'stages' => $this->stageMap(),
            'apartments' => $this->idMap('apartments', true),
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
            throw new \RuntimeException('Invalid apartment ownership ID');
        }

        /** @var array<int, string> $apartments */
        $apartments = $context['apartments'];
        $apartmentId = $this->requireMappedId(
            $apartments,
            BitrixValue::firstInt($item['parentId144'] ?? null)
                ?? BitrixValue::firstInt($item['ufCrm10_1693823301'] ?? null),
            'Apartment link is empty',
            'Apartment not found locally'
        );

        /** @var array<string, int> $stages */
        $stages = $context['stages'];
        /** @var array<string, array<string, string>> $enums */
        $enums = $context['enums'];
        $stageCode = trim((string) ($item['stageId'] ?? ''));

        return [
            'id' => $existingId ?? (string) Str::uuid(),
            'portal_id' => $this->portalId(),
            'bitrix_id' => $bitrixId,
            'title' => BitrixValue::str($item['title'] ?? null),
            'stage_id' => $stageCode !== '' ? ($stages[$stageCode] ?? null) : null,
            'apartment_id' => $apartmentId,
            'contract_start_date' => BitrixValue::date($item['ufCrm10_1693823247516'] ?? null),
            'contract_end_date' => BitrixValue::date($item['ufCrm10_1693823282826'] ?? null),
            'pml_start_date' => BitrixValue::date($item['ufCrm10_1708956056'] ?? null),
            'pml_end_date' => BitrixValue::date($item['ufCrm10_1708955996'] ?? null),
            'dtcm_start_date' => BitrixValue::date($item['ufCrm10_1714652654'] ?? null),
            'dtcm_end_date' => BitrixValue::date($item['ufCrm10_1714652687'] ?? null),
            'termination_date' => BitrixValue::date($item['ufCrm10TerminationDate'] ?? null),
            'termination_reason' => BitrixEnums::resolve($enums, 'ufCrm10_1727422412', $item['ufCrm10_1727422412'] ?? null),
            ...$this->stamps($item, $now),
        ];
    }
}
