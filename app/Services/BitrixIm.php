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

    /**
     * Send a unified low-balance notice to the Bitrix IM channel.
     *
     * @param  array{portal: string, service: string, url?: string, line?: string|null, line_url?: string, pay_method?: string, date: string, balance?: string}  $payload
     */
    public function notice(array $payload): void
    {
        $this->send($this->noticeMessage($payload));
    }

    /**
     * Build a unified low-balance notice.
     *
     * @param  array{portal: string, service: string, url?: string, line?: string|null, line_url?: string, pay_method?: string, date: string, balance?: string}  $payload
     */
    public function noticeMessage(array $payload): string
    {
        $payMethod = trim((string) ($payload['pay_method'] ?? ''));

        $lines = [
            '🔔 Напоминание об оплате сервиса',
            '',
            'Портал: '.$payload['portal'],
            'Сервис: '.$this->url($payload['service'], (string) ($payload['url'] ?? '')),
        ];

        $line = trim((string) ($payload['line'] ?? ''));
        if ($line !== '') {
            $lines[] = 'Линия: '.$this->url($line, (string) ($payload['line_url'] ?? ''));
        }

        if ($payMethod !== '') {
            $lines[] = 'Способ оплаты: '.$payMethod;
        }

        $payment = 'Дата платежа: '.$payload['date'];
        $balance = trim((string) ($payload['balance'] ?? ''));
        if ($balance !== '') {
            $payment .= ' \ Баланс: '.$balance;
        }

        $lines[] = $payment;
        $lines[] = '⚠️ Требуемое действие:';
        $lines[] = $this->action($payMethod);

        return implode("\n", $lines);
    }

    /**
     * Map payment method to the required action.
     */
    private function action(string $payMethod): string
    {
        return match ($payMethod) {
            'Счёт' => 'Выставить счёт',
            'Карта' => 'Оплатить картой',
            default => 'Пополнить баланс',
        };
    }

    /**
     * Wrap a label with a Bitrix IM URL when configured.
     */
    public function url(string $label, string $url): string
    {
        $url = trim($url);

        return $url !== '' ? '[URL='.$url.']'.$label.'[/URL]' : $label;
    }
}
