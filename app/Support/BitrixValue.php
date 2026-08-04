<?php

namespace App\Support;

use Carbon\Carbon;

class BitrixValue
{
    /**
     * Normalize Bitrix scalar to trimmed string or null.
     */
    public static function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * Normalize Bitrix scalar to int or null.
     */
    public static function asInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Normalize Bitrix scalar to non-negative int or null.
     */
    public static function uint(mixed $value): ?int
    {
        $string = self::str($value);
        if ($string === null || ! is_numeric($string)) {
            return null;
        }

        $int = (int) $string;

        return $int < 0 ? null : $int;
    }

    /**
     * Normalize Bitrix scalar to float or null.
     */
    public static function asFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Parse Bitrix datetime string to Carbon or null.
     */
    public static function dt(mixed $value): ?Carbon
    {
        $string = self::str($value);
        if ($string === null) {
            return null;
        }

        return Carbon::parse($string);
    }

    /**
     * Parse Bitrix date/datetime to Y-m-d string or null.
     */
    public static function date(mixed $value): ?string
    {
        return self::dt($value)?->toDateString();
    }

    /**
     * Normalize Bitrix money field to decimal string or null.
     */
    public static function money(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_array($value)) {
            $amount = $value['amount'] ?? $value['value'] ?? reset($value);
            $value = $amount;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        if (str_contains($string, '|')) {
            $string = explode('|', $string, 2)[0];
        }

        if (! is_numeric($string)) {
            return null;
        }

        return number_format((float) $string, 2, '.', '');
    }

    /**
     * Extract first numeric id from Bitrix multi-value / scalar field.
     */
    public static function firstInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_array($value)) {
            return self::firstInt(reset($value));
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $string = self::str($value);
        if ($string === null) {
            return null;
        }

        if (is_numeric($string)) {
            return (int) $string;
        }

        if (preg_match('/\d+/', $string, $matches) === 1) {
            return (int) $matches[0];
        }

        return null;
    }

    /**
     * Return first non-empty normalized string among values.
     */
    public static function firstStr(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $normalized = self::str($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }
}
