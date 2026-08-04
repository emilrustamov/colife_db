<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApartmentOwnership extends Model
{
    use HasFactory;

    protected $table = 'apartment_ownerships';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'bitrix_id',
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
        'is_deleted',
        'bitrix_created_at',
        'bitrix_updated_at',
        'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contract_start_date' => 'date',
            'contract_end_date' => 'date',
            'pml_start_date' => 'date',
            'pml_end_date' => 'date',
            'dtcm_start_date' => 'date',
            'dtcm_end_date' => 'date',
            'termination_date' => 'date',
            'is_deleted' => 'boolean',
            'bitrix_created_at' => 'datetime',
            'bitrix_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
