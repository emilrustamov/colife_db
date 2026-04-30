<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnitStay extends Model
{
    use HasFactory;

    protected $table = 'unit_stays';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'bitrix_id',
        'unit_id',
        'tenant_contact_id',
        'co_tenant_contact_id',
        'deal_id',
        'contract_type',
        'check_in_date',
        'check_out_date',
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
            'bitrix_created_at' => 'datetime',
            'bitrix_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'is_deleted' => 'boolean',
        ];
    }
}

