<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OlBotChat extends Model
{
    protected $table = 'ol_bot_chats';

    protected $fillable = [
        'chat_id',
        'dialog_id',
        'started_at',
        'ended_at',
        'messages_count',
        'duration_sec',
        'first_response_sec',
        'avg_response_sec',
        'max_response_sec',
        'escalated',
        'escalate_reason',
        'escalate_bucket',
        'credits',
        'tags',
        'category',
        'line_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'escalated' => 'boolean',
            'credits' => 'float',
        ];
    }

    /**
     * Chat messages.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(OlBotMessage::class, 'chat_id', 'chat_id');
    }
}
