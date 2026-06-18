<?php

namespace App\Services;

use App\Enums\BitrixOutgoingWebhookContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BitrixOutgoingApplicationTokenVerifier
{
    /**
     * @return array{0: bool, 1: string}
     */
    public function verify(Request $request, BitrixOutgoingWebhookContext $context): array
    {
        $expected = $this->expectedToken($context);
        $channel = $context === BitrixOutgoingWebhookContext::OpenLines
            ? 'bitrix_open_lines'
            : 'bitrix_contacts';

        if ($expected === '') {
            Log::channel($channel)->error('Bitrix webhook token config is empty');

            return [false, $channel];
        }

        $incoming = (string) data_get($request->all(), 'auth.application_token', '');
        if ($incoming === '') {
            return [false, $channel];
        }

        return [hash_equals($expected, $incoming), $channel];
    }

    private function expectedToken(BitrixOutgoingWebhookContext $context): string
    {
        if ($context === BitrixOutgoingWebhookContext::OpenLines) {
            $dedicated = (string) config('services.bitrix.open_lines_application_token', '');
            if ($dedicated !== '') {
                return $dedicated;
            }
        }

        $expected = (string) config('services.bitrix.webhook_token', '');
        if ($expected === '') {
            $expected = (string) config('services.bitrix_contacts.event_token', '');
        }

        return $expected;
    }
}
