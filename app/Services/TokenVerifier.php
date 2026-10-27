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
            $incoming = $this->requestToken($request);
            $domain = $this->requestDomain($request);
            $channel = $context === WebhookContext::Cleaning ? 'bitrix_cleaning' : 'bitrix_pauses';

            return [$incoming !== '' && $this->oauth->isAllowedPortal($domain), $channel];
        }

        if ($context === WebhookContext::OpenLines) {
            return $this->verifyOpenLines($request);
        }

        $expected = $this->expectedCrmToken();
        $channel = 'bitrix_contacts';

        if ($expected === '') {
            Log::channel($channel)->error('Bitrix webhook token config is empty');

            return [false, $channel];
        }

        $incoming = $this->requestToken($request);
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
        $incoming = $this->requestToken($request);
        if ($incoming === '') {
            return [false, $channel];
        }

        $domain = $this->requestDomain($request);
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

    /**
     * Portal auth: non-empty application_token + allowlisted domain (legacy Pause/Cleaning behavior).
     */
    public function matchesDomainToken(Request $request, WebhookContext $context = WebhookContext::Bizproc): bool
    {
        [$ok] = $this->verify($request, $context);

        return $ok;
    }

    /**
     * Incoming Bitrix application_token from request payload.
     */
    public function requestToken(Request $request): string
    {
        return (string) (
            data_get($request->all(), 'auth.application_token')
            ?: $request->input('application_token', '')
            ?: $request->input('token', '')
            ?: $request->header('X-Alma-Bridge-Token', '')
        );
    }

    /**
     * Portal domain from Bitrix auth payload.
     */
    public function requestDomain(Request $request): string
    {
        return $this->oauth->normalizeDomain((string) (
            data_get($request->all(), 'auth.domain')
            ?: $request->input('DOMAIN', '')
            ?: $request->input('domain', '')
        ));
    }

    private function expectedCrmToken(): string
    {
        $expected = (string) config('services.bitrix.webhook_token', '');
        if ($expected === '') {
            $expected = (string) config('services.bitrix_contacts.event_token', '');
        }

        return $expected;
    }
}
