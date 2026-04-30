<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pipeline extends Model
{
    use HasFactory;

    protected $table = 'pipelines';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'entity_type',
        'bitrix_id',
        'name',
        'sort',
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
            'is_deleted' => 'boolean',
            'bitrix_created_at' => 'datetime',
            'bitrix_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}

