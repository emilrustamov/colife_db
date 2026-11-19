<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CleaningStaff extends Model
{
    protected $table = 'cleaning_staff';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'bitrix_id',
        'name',
        'sort',
        'is_deleted',
        'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_deleted' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Active staff names for the cleaning form select (synced from Bitrix list).
     * Falls back to config when the local table is still empty.
     *
     * @return list<string>
     */
    public static function namesForForm(): array
    {
        $names = static::query()
            ->where('is_deleted', false)
            ->where('name', '!=', '')
            ->orderBy('sort')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $names = array_values(array_unique(array_map(
            static fn ($name): string => trim((string) $name),
            $names
        )));
        $names = array_values(array_filter($names, static fn (string $name): bool => $name !== ''));

        if ($names !== []) {
            return $names;
        }

        $fallback = config('services.cleaning.staff', []);
        if (! is_array($fallback)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($name): string => trim((string) $name),
            $fallback
        ), static fn (string $name): bool => $name !== ''));
    }
}
