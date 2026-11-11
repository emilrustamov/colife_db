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
        'unit_id',
        'bitrix_ownership_id',
        'title',
        'layout',
        'is_active',
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
