<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiskSyncedFile extends Model
{
    protected $table = 'disk_synced_files';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'list_id',
        'element_bitrix_id',
        'folder_bitrix_id',
        'folder_name',
        'folder_url',
        'field_code',
        'bitrix_file_id',
        'content_version',
        'original_name',
        'local_path',
        'is_deleted',
        'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'list_id' => 'integer',
            'element_bitrix_id' => 'integer',
            'folder_bitrix_id' => 'integer',
            'bitrix_file_id' => 'integer',
            'content_version' => 'integer',
            'is_deleted' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }
}
