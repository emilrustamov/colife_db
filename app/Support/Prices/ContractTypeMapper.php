<?php

namespace App\Support\Prices;

use App\Models\ContractType;
use Illuminate\Support\Facades\Cache;

final class ContractTypeMapper
{
    public const SHORT = 'краткосрок';

    public const LONG = 'долгосрок';

    private const CACHE_KEY = 'prices:contract_types_map';

    public static function bucketFromLabel(?string $label): ?string
    {
        if ($label === null || $label === '') {
            return null;
        }

        $map = self::map();
        if (isset($map['by_name'][$label])) {
            return $map['by_name'][$label];
        }

        if (ctype_digit($label)) {
            return $map['by_id'][(int) $label] ?? null;
        }

        return null;
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    public static function labelsFromIds(array $ids): array
    {
        $map = self::map();
        $labels = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (isset($map['labels_by_id'][$id])) {
                $labels[] = $map['labels_by_id'][$id];
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * @return array<string, list<array{id: int, name: string}>>
     */
    public static function subtypesByBucket(): array
    {
        return self::map()['subtypes'];
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{
     *     by_name: array<string, string>,
     *     by_id: array<int, string>,
     *     labels_by_id: array<int, string>,
     *     subtypes: array<string, list<array{id: int, name: string}>>
     * }
     */
    private static function map(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, static function (): array {
            $byName = [];
            $byId = [];
            $labelsById = [];
            $subtypes = [
                self::SHORT => [],
                self::LONG => [],
            ];

            $rows = ContractType::query()
                ->where('is_active', true)
                ->orderBy('sort')
                ->orderBy('name')
                ->get(['bitrix_enum_id', 'name', 'bucket']);

            foreach ($rows as $row) {
                $bucket = $row->bucket;
                if ($bucket !== self::SHORT && $bucket !== self::LONG) {
                    continue;
                }

                $byName[$row->name] = $bucket;
                if ($row->bitrix_enum_id === null) {
                    continue;
                }

                $id = (int) $row->bitrix_enum_id;
                $byId[$id] = $bucket;
                $labelsById[$id] = $row->name;
                $subtypes[$bucket][] = [
                    'id' => $id,
                    'name' => $row->name,
                ];
            }

            return [
                'by_name' => $byName,
                'by_id' => $byId,
                'labels_by_id' => $labelsById,
                'subtypes' => $subtypes,
            ];
        });
    }
}
