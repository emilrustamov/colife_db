<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlmaContact extends Model
{
    protected $fillable = [
        'alma_id',
        'bitrix_id',
        'email',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'alma_id' => 'integer',
    ];
}
