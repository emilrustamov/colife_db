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
        'deal_id',
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

    /**
     * Absolute Bitrix deal URL if deal_id is set.
     */
    public function dealUrl(?string $portalDomain = null): ?string
    {
        $dealId = trim((string) $this->deal_id);
        if ($dealId === '') {
            return null;
        }
        $domain = trim((string) ($portalDomain ?: config('open_lines_source.portal_domain', 'colifepacific.bitrix24.com')));
        $domain = preg_replace('#^https?://#', '', $domain) ?: 'colifepacific.bitrix24.com';

        return 'https://'.$domain.'/crm/deal/details/'.$dealId.'/';
    }

    /**
     * Absolute Bitrix Open Lines / IM dialog URL if dialog_id is set.
     */
    public function openLineUrl(?string $portalDomain = null): ?string
    {
        $dialogId = trim((string) $this->dialog_id);
        if ($dialogId === '') {
            return null;
        }
        $domain = trim((string) ($portalDomain ?: config('open_lines_source.portal_domain', 'colifepacific.bitrix24.com')));
        $domain = preg_replace('#^https?://#', '', $domain) ?: 'colifepacific.bitrix24.com';

        return 'https://'.$domain.'/online/?IM_DIALOG='.rawurlencode($dialogId);
    }

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
