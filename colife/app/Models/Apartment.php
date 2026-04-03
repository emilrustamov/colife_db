<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Apartment extends Model
{
    use HasFactory;

    protected $table = 'apartments';

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
        'building_id',
        'landlord_contact_id',
        'metro_station_id',
        'apartment_type_id',
        'internal_number',
        'address',
        'property_mode',
        'rental_type',
        'status',
        'busy_reason',
        'work_model',
        'floor',
        'metro_minutes',
        'transport_type',
        'parking_number',
        'google_maps_link',
        'bathrooms',
        'rooms',
        'area_sqm',
        'wifi_name',
        'wifi_password',
        'access_cards',
        'parking_cards',
        'keys_count',
        'lock_pass',
        'keybox_code',
        'room_keys_notes',
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
            'bathrooms' => 'integer',
            'rooms' => 'integer',
            'area_sqm' => 'decimal:2',
            'floor' => 'integer',
            'metro_minutes' => 'integer',
            'access_cards' => 'integer',
            'parking_cards' => 'integer',
            'keys_count' => 'integer',
            'bitrix_created_at' => 'datetime',
            'bitrix_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}

