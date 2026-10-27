<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BitrixToken extends Model
{
    protected $fillable = [
        'domain',
        'auth_id',
        'refresh_id',
        'refresh_token',
        'access_token',
        'expires_in',
        'expires_at',
        'member_id',
        'application_token',
        'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_in' => 'integer',
            'user_id' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
