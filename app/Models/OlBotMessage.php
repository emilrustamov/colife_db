<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OlBotMessage extends Model
{
    public $timestamps = false;

    protected $table = 'ol_bot_messages';

    protected $fillable = [
        'chat_id',
        'dialog_id',
        'role',
        'body',
        'response_sec',
        'credits',
        'meta_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'credits' => 'float',
            'meta_json' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
