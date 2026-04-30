<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientBalance extends Model
{
    protected $fillable = [
        'client_id',
        'apartment_id',
        'year',
        'month',
        'balance',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'client_id' => 'integer',
        'apartment_id' => 'string',
        'year' => 'integer',
        'month' => 'integer',
        'balance' => 'decimal:2',
    ];
}
