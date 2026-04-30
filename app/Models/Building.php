<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Building extends Model
{
    use HasFactory;

    protected $table = 'buildings';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'bitrix_id',
        'name',
        'pool',
        'jacuzzi',
        'gym',
        'sauna',
        'parking',
        'elevator',
        'security',
        'bitrix_created_at',
        'bitrix_updated_at',
        'last_synced_at',
        'is_deleted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pool' => 'boolean',
            'jacuzzi' => 'boolean',
            'gym' => 'boolean',
            'sauna' => 'boolean',
            'parking' => 'boolean',
            'elevator' => 'boolean',
            'security' => 'boolean',
            'is_deleted' => 'boolean',
            'bitrix_created_at' => 'datetime',
            'bitrix_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}

