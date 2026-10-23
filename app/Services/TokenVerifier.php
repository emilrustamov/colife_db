<?php

namespace App\Services;

use App\Enums\WebhookContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TokenVerifier
{
    public function __construct(private readonly BitrixOAuth $oauth) {}

    /**
     * @return array{0: bool, 1: string}
     */
    public function verify(Request $request, WebhookContext $context): array
    {
        if ($context === WebhookContext::Bizproc || $context === WebhookContext::Cleaning) {
            $incoming = (string) data_get($request->all(), 'auth.application_token', '');
            $domain = $this->oauth->normalizeDomain(
                (string) (data_get($request->all(), 'auth.domain') ?: $request->input('DOMAIN', ''))
            );
            $channel = $context === WebhookContext::Cleaning ? 'bitrix_cleaning' : 'bitrix_pauses';

            return [$incoming !== '' && $this->oauth->isAllowedPortal($domain), $channel];
        }

        if ($context === WebhookContext::OpenLines) {
            return $this->verifyOpenLines($request);
        }

        $expected = $this->expectedToken($context);
        $channel = 'bitrix_contacts';

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

    /**
     * @return array{0: bool, 1: string}
     */
    private function verifyOpenLines(Request $request): array
    {
        $channel = 'bitrix_open_lines';
        $incoming = (string) data_get($request->all(), 'auth.application_token', '');
        if ($incoming === '') {
            return [false, $channel];
        }

        $domain = $this->oauth->normalizeDomain((string) data_get($request->all(), 'auth.domain', ''));
        $expectedDomain = $this->oauth->normalizeDomain((string) config('open_lines_source.portal_domain'));
        if ($domain === '' || $expectedDomain === '' || strcasecmp($domain, $expectedDomain) !== 0) {
            return [false, $channel];
        }

        $dedicated = (string) config('services.bitrix.open_lines_application_token', '');
        if ($dedicated !== '') {
            return [hash_equals($dedicated, $incoming), $channel];
        }

        return [true, $channel];
    }

    private function expectedToken(WebhookContext $context): string
    {
        $expected = (string) config('services.bitrix.webhook_token', '');
        if ($expected === '') {
            $expected = (string) config('services.bitrix_contacts.event_token', '');
        }

        return $expected;
    }
}
