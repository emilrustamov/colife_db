<?php

namespace App\Services\Profiles;

use App\Models\Unit;
use App\Support\BitrixValue;
use App\Support\EntityMap;
use Carbon\Carbon;
use Illuminate\Support\Str;

class UnitProfile extends SpaProfile
{
    public const ENTITY_TYPE_ID = 167;

    /**
     * @return list<string>
     */
    public static function select(): array
    {
        return [
            'id', 'title', 'stageId', 'createdTime', 'updatedTime',
            'ufCrm8_1684429208', 'ufCrm8_1698838056004', 'ufCrm6_1707234311507',
        ];
    }

    public function entity(): string
    {
        return 'units';
    }

    protected function model(): string
    {
        return Unit::class;
    }

    protected function typeId(): int
    {
        $typeId = EntityMap::typeId('ae', 'units');

        return $typeId > 0 ? $typeId : self::ENTITY_TYPE_ID;
    }

    protected function event(): string
    {
        return 'bitrix.unit';
    }

    protected function stageEntity(): string
    {
        return 'unit';
    }

    /**
     * @return list<string>
     */
    protected function fields(): array
    {
        return [
            'apartment_id',
            'title',
            'stage_id',
            'internal_number',
        ];
    }

    /**
     * @return array{stages: array<string, int>, apartments: array<int, string>}
     */
    protected function context(): array
    {
        return [
            'stages' => $this->stageMap(),
            'apartments' => $this->idMap('apartments', true),
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

        /** @var array<int, string> $apartments */
        $apartments = $context['apartments'];
        $apartmentId = $this->requireMappedId(
            $apartments,
            BitrixValue::firstInt($item['ufCrm8_1684429208'] ?? null),
            'Apartment link is empty',
            'Apartment not found locally'
        );

        /** @var array<string, int> $stages */
        $stages = $context['stages'];
        $stageCode = trim((string) ($item['stageId'] ?? ''));

        return [
            'id' => $existingId ?? (string) Str::uuid(),
            'portal_id' => $this->portalId(),
            'bitrix_id' => $bitrixId,
            'apartment_id' => $apartmentId,
            'title' => BitrixValue::str($item['title'] ?? null),
            'stage_id' => $stageCode !== '' ? ($stages[$stageCode] ?? null) : null,
            'internal_number' => BitrixValue::firstStr(
                $item['ufCrm8_1698838056004'] ?? null,
                $item['ufCrm6_1707234311507'] ?? null
            ),
            ...$this->stamps($item, $now),
        ];
    }
}
