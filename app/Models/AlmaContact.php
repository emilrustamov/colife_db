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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'alma_id' => 'integer',
        ];
    }
}
