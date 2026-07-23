<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Utility extends Model
{
    protected $table = 'utilities';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'bitrix_id',
        'name',
        'utility_type_id',
        'utility_type',
        'provider_company',
        'account_number',
        'login',
        'password',
        'email_for_registration',
        'name_used_for_registration',
        'apartment_id',
        'apartment_bitrix_id',
        'acquisition_deal_id',
        'autopayment_date',
        'apartment_text',
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
            'is_deleted' => 'boolean',
            'autopayment_date' => 'datetime',
            'bitrix_created_at' => 'datetime',
            'bitrix_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Linked local apartment.
     */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }
}
