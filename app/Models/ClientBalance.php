<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientBalance extends Model
{
    protected $fillable = [
        'apartment_bitrix_id',
        'year',
        'month',
        'balance',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'apartment_bitrix_id' => 'string',
            'year' => 'integer',
            'month' => 'integer',
            'balance' => 'decimal:2',
        ];
    }
}
