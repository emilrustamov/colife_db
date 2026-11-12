<?php

namespace App\Services;

use RuntimeException;

class LandlordFinanceSheetParser
{
    /**
     * Build Alma landlord_property_finance payload items for one year sheet.
     *
     * @param  list<list<mixed>>  $rows  Values from B{start}:M{end} (12 months per row)
     * @return list<array<string, mixed>>
     */
    public function buildPayload(
        string $landlordId,
        string $apartId,
        int $year,
        array $rows,
    ): array {
        $map = (array) config('services.landlord_finance.rows');
        $startRow = (int) min($map);
        $vat = $this->monthValues($rows, (int) $map['vat'], $startRow);
        $rent = $this->monthValues($rows, (int) $map['rent'], $startRow);
        $commission = $this->monthValues($rows, (int) $map['commission'], $startRow);
        $quotation = $this->monthValues($rows, (int) $map['quotation'], $startRow);
        $internet = $this->monthValues($rows, (int) $map['internet'], $startRow);
        $dewa = $this->monthValues($rows, (int) $map['dewa'], $startRow);
        $ac = $this->monthValues($rows, (int) $map['ac'], $startRow);
        $gas = $this->monthValues($rows, (int) $map['gas'], $startRow);
        $serviceCharge = $this->monthValues($rows, (int) $map['service_charge'], $startRow);
        $dtcmPermit = $this->monthValues($rows, (int) $map['dtcm_permit'], $startRow);
        $maintenance = $this->monthValues($rows, (int) $map['maintenance'], $startRow);
        $otherExpenses = $this->monthValues($rows, (int) $map['other_expenses'], $startRow);
        $received = $this->monthValues($rows, (int) $map['received_from_landlord'], $startRow);
        $transferred = $this->monthValues($rows, (int) $map['transferred_to_landlord'], $startRow);

        $payload = [];
        for ($index = 0; $index < 12; $index++) {
            $rentServices = $this->buildRentServicesMap([
                'rent' => [
                    'net' => $rent[$index],
                    'vat_amount' => $vat[$index],
                ],
            ]);

            $expenses = $this->buildExpenseMap([
                'service-fee' => $commission[$index],
                'quotation' => $quotation[$index],
                'internet' => $internet[$index],
                'dewa' => $dewa[$index],
                'ac' => $ac[$index],
                'gas' => $gas[$index],
                'service-charge' => $serviceCharge[$index],
                'dtcm-permit' => $dtcmPermit[$index],
                'maintenace' => $maintenance[$index],
                'other-expenses' => $otherExpenses[$index],
            ]);

            $receivedAmount = $this->normalizeDecimal($received[$index] ?? 0);
            $transferredAmount = $this->normalizeDecimal($transferred[$index] ?? 0);

            $payload[] = [
                'landlord_id' => $landlordId,
                'unit_id' => $apartId,
                'month' => sprintf('%02d-%d', $index + 1, $year),
                'rent_services' => $rentServices === [] ? new \stdClass() : $rentServices,
                'received_from_landlord' => $receivedAmount,
                'transferred_to_landlord' => $transferredAmount,
                'expenses' => $expenses === [] ? new \stdClass() : $expenses,
            ];
        }

        return $payload;
    }

    /**
     * Extract 12 month values for a sheet row number from a contiguous block.
     *
     * @param  list<list<mixed>>  $rows
     * @return list<mixed>
     */
    private function monthValues(array $rows, int $sheetRow, int $startRow): array
    {
        $offset = $sheetRow - $startRow;
        $row = $rows[$offset] ?? [];
        $values = [];
        for ($i = 0; $i < 12; $i++) {
            $values[] = $row[$i] ?? '';
        }

        return $values;
    }

    /**
     * @param  array<string, array{net: mixed, vat_amount: mixed}>  $valuesByCode
     * @return array<string, array{net: string, vat_amount: string}>
     */
    private function buildRentServicesMap(array $valuesByCode): array
    {
        $result = [];
        foreach ($valuesByCode as $code => $entry) {
            $net = $this->toNumberOrZero($entry['net'] ?? 0);
            $vatAmount = $this->toNumberOrZero($entry['vat_amount'] ?? 0);
            if ($net === 0.0 && $vatAmount === 0.0) {
                continue;
            }
            $result[$code] = [
                'net' => $this->normalizeDecimal($net),
                'vat_amount' => $this->normalizeDecimal($vatAmount),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $valuesByCode
     * @return array<string, string>
     */
    private function buildExpenseMap(array $valuesByCode): array
    {
        $result = [];
        foreach ($valuesByCode as $code => $value) {
            $amount = $this->toNumberOrZero($value);
            if ($amount === 0.0) {
                continue;
            }
            $result[$code] = $this->normalizeDecimal($amount);
        }

        return $result;
    }

    /**
     * Normalize amount to Alma decimal string.
     */
    private function normalizeDecimal(mixed $value): string
    {
        $num = $this->toNumberOrZero($value);

        return number_format(round($num, 2), 6, '.', '');
    }

    /**
     * Parse sheet cell to float, treating empty as zero.
     */
    private function toNumberOrZero(mixed $value): float
    {
        if ($value === '' || $value === null) {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $raw = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', (string) $value) ?? '';
        $raw = str_replace(',', '.', $raw);
        if ($raw === '' || $raw === '-') {
            return 0.0;
        }

        if (! is_numeric($raw)) {
            throw new RuntimeException('Некорректная сумма: '.$value);
        }

        return (float) $raw;
    }
}
