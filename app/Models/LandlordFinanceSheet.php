<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LandlordFinanceSheet extends Model
{
    protected $fillable = [
        'spreadsheet_id',
        'sheet_name',
        'landlord_id',
        'landlord_id_2',
        'apart_id',
        'bitrix_ownership_id',
        'title',
        'source_url',
        'layout',
        'is_active',
        'is_external',
        'id_source',
        'last_synced_at',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_external' => 'boolean',
            'bitrix_ownership_id' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Scope active registry rows.
     *
     * @param  Builder<LandlordFinanceSheet>  $query
     * @return Builder<LandlordFinanceSheet>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
