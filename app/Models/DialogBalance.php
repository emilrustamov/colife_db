<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DialogBalance extends Model
{
    protected $fillable = [
        'line_id',
        'line_name',
        'phone_number',
        'total_limit',
        'used',
        'remaining',
        'collected_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_limit' => 'integer',
            'used' => 'integer',
            'remaining' => 'integer',
            'collected_at' => 'date',
        ];
    }
}
