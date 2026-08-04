<?php

namespace App\Services;

use RuntimeException;

class BitrixIm
{
    public function __construct(
        private readonly BitrixWebhook $webhookClient
    ) {}

    /**
     * Send a message to a Bitrix24 group chat (channel) via im.message.add.
     */
    public function send(string $message): void
    {
        $webhook = (string) config('services.bitrix_im.webhook');
        $dialogId = (string) config('services.bitrix_im.dialog_id');

        if (trim($webhook) === '' || $dialogId === '') {
            throw new RuntimeException('Bitrix IM channel webhook or dialog id is not configured.');
        }

        $this->webhookClient->call('im.message.add', [
            'DIALOG_ID' => $dialogId,
            'MESSAGE' => $message,
        ], $webhook);
    }
}
