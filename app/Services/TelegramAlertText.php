<?php

namespace App\Services;

class TelegramAlertText
{
    public function __construct(
        private readonly BitrixOAuth $oauth,
    ) {}

    /**
     * Build short HTML alert: bold problem, portal, optional action.
     */
    public function message(
        string $emoji,
        string $title,
        ?string $domain = null,
        ?string $action = null,
    ): string {
        $parts = [
            $emoji.' <b>'.$this->escape($title).'</b>',
        ];

        if ($domain !== null && trim($domain) !== '') {
            $parts[] = $this->portalLine($domain);
        }

        if ($action !== null && trim($action) !== '') {
            $parts[] = '';
            $parts[] = '➡️ '.$this->escape(trim($action));
        }

        return implode("\n", $parts);
    }

    /**
     * Human portal line with bold code and plain URL.
     */
    public function portalLine(string $domain): string
    {
        $code = $this->oauth->portalCode($domain);
        $url = $this->oauth->portalUrl($domain);

        return 'Портал: <b>'.$this->escape($code).'</b> · '.$this->escape($url);
    }

    /**
     * Escape text for Telegram HTML parse_mode.
     */
    public function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
