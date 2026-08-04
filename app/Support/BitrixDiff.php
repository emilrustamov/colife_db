<?php

namespace App\Support;

class BitrixDiff
{
    /**
     * Whether activity payload changed beyond empty/bool normalization.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>  $newValues
     * @param  list<string>  $ignoreKeys
     */
    public static function changed(?array $oldValues, array $newValues, array $ignoreKeys = []): bool
    {
        if ($oldValues === null) {
            return true;
        }

        $ignored = array_fill_keys($ignoreKeys, true);
        $keys = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));

        foreach ($keys as $key) {
            if (isset($ignored[$key])) {
                continue;
            }

            if (self::normalize($oldValues[$key] ?? null) !== self::normalize($newValues[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a payload value for equality comparison.
     */
    public static function normalize(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }
}
