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
        'title',
        'stage_id',
        'unit_id',
        'tenant_contact_id',
        'co_tenant_contact_id',
        'deal_id',
        'contract_type',
        'type_of_deal',
        'type_of_payment',
        'contract_start_date',
        'contract_end_date',
        'months_of_stay',
        'rental_price',
        'deposit',
        'total_contract_amount',
        'opportunity',
        'currency_id',
        'passport_number',
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
            'contract_start_date' => 'date',
            'contract_end_date' => 'date',
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'rental_price' => 'decimal:2',
            'deposit' => 'decimal:2',
            'total_contract_amount' => 'decimal:2',
            'opportunity' => 'decimal:2',
            'months_of_stay' => 'integer',
            'is_deleted' => 'boolean',
        ];
    }
}

