<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OlBotApartment extends Model
{
    protected $table = 'ol_bot_apartments';

    protected $fillable = [
        'uid',
        'source',
        'title',
        'sku',
        'unit_number',
        'link',
        'area',
        'type',
        'capacity',
        'available_from',
        'rent_type',
        'category',
        'price_unit',
        'price',
        'price_variants_json',
        'text_body',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'price_variants_json' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
