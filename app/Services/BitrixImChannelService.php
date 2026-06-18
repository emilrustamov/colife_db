<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class BitrixImChannelService
{
    /**
     * Send a message to a Bitrix24 group chat (channel) via im.message.add.
     */
    public function send(string $message): void
    {
        $webhook = rtrim((string) config('services.bitrix_im.webhook'), '/').'/';
        $dialogId = (string) config('services.bitrix_im.dialog_id');

        if ($webhook === '/' || $dialogId === '') {
            throw new RuntimeException('Bitrix IM channel webhook or dialog id is not configured.');
        }

        Http::asForm()
            ->post($webhook.'im.message.add', [
                'DIALOG_ID' => $dialogId,
                'MESSAGE' => $message,
            ])
            ->throw();
    }
}
