<?php

namespace App\Services\Prices;

use App\Support\Prices\ContractTypeMapper;
use InvalidArgumentException;

final class ReportFilters
{
    public const MIN_REPORT_YEAR = 2023;

    /**
     * @param  list<int>  $years
     * @param  list<int>  $months
     * @param  list<string>  $districts
     * @param  list<string>  $buildings
     * @param  list<string>  $apartmentTypes
     * @param  list<int>  $contractTypeIds
     * @param  list<string>  $units
     */
    public function __construct(
        public readonly array $years,
        public readonly array $months = [],
        public readonly array $districts = [],
        public readonly array $buildings = [],
        public readonly array $apartmentTypes = [],
        public readonly ?string $contractType = null,
        public readonly array $contractTypeIds = [],
        public readonly array $units = [],
        public readonly int $offset = 0,
        public readonly int $limit = 20,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $years = self::ints($input['years'] ?? null);
        if ($years === []) {
            throw new InvalidArgumentException('Параметр years обязателен');
        }

        $maxYears = max(1, (int) config('services.prices.export_max_years', 5));
        $years = array_values(array_unique(array_filter(
            $years,
            static fn (int $year): bool => $year >= self::MIN_REPORT_YEAR && $year <= 2100
        )));
        sort($years);
        if ($years === []) {
            throw new InvalidArgumentException('Параметр years обязателен');
        }
        if (count($years) > $maxYears) {
            throw new InvalidArgumentException("Можно выбрать не больше {$maxYears} лет");
        }

        $months = array_values(array_filter(
            self::ints($input['months'] ?? null),
            static fn (int $month): bool => $month >= 1 && $month <= 12
        ));

        $contractType = ! empty($input['contract_type']) ? (string) $input['contract_type'] : null;
        if ($contractType !== null && ! in_array($contractType, [ContractTypeMapper::SHORT, ContractTypeMapper::LONG], true)) {
            throw new InvalidArgumentException('Некорректный contract_type');
        }

        return new self(
            years: $years,
            months: $months,
            districts: array_slice(self::strings($input['districts'] ?? null), 0, 100),
            buildings: array_slice(self::strings($input['buildings'] ?? null), 0, 200),
            apartmentTypes: array_slice(self::strings($input['apartment_types'] ?? null), 0, 50),
            contractType: $contractType,
            contractTypeIds: array_slice(self::ints($input['contract_type_ids'] ?? null), 0, 20),
            units: array_slice(self::strings($input['units'] ?? null), 0, 500),
            offset: max(0, min(100_000, (int) ($input['offset'] ?? 0))),
            limit: max(1, min(50, (int) ($input['limit'] ?? 20))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function cachePayload(): array
    {
        return [
            $this->years,
            $this->months,
            $this->districts,
            $this->buildings,
            $this->apartmentTypes,
            $this->contractType,
            $this->contractTypeIds,
            $this->units,
            $this->offset,
            $this->limit,
        ];
    }

    /**
     * @return list<int>
     */
    public static function ints(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return array_values(array_filter(array_map('intval', $value)));
        }

        return array_values(array_filter(array_map('intval', explode(',', (string) $value))));
    }

    /**
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return array_values(array_filter(
                array_map(static fn ($item): string => trim((string) $item), $value),
                static fn (string $item): bool => $item !== ''
            ));
        }

        return array_values(array_filter(
            array_map('trim', explode(',', (string) $value)),
            static fn (string $item): bool => $item !== ''
        ));
    }
}
