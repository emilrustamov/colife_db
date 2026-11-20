<?php

namespace App\Services;

use App\Enums\WebhookContext;
use App\Models\BitrixToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
            $channel = $context === WebhookContext::Cleaning ? 'bitrix_cleaning' : 'bitrix_pauses';

            return [$this->verifyPortalApplication($request), $channel];
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

        $expected = trim((string) config('services.bitrix.open_lines_application_token', ''));
        if ($expected === '') {
            Log::channel($channel)->error('Open Lines application token config is empty');

            return [false, $channel];
        }

        return [hash_equals($expected, $incoming), $channel];
    }

    /**
     * Pause/Cleaning: allowlisted domain + Bitrix application_token.
     * When a known secret exists (DB or env), require an exact match.
     */
    private function verifyPortalApplication(Request $request): bool
    {
        $incoming = $this->requestToken($request);
        $domain = $this->requestDomain($request);
        if ($incoming === '' || ! $this->oauth->isAllowedPortal($domain)) {
            return false;
        }

        $expected = $this->expectedPortalApplicationToken($domain);
        if ($expected !== '') {
            return hash_equals($expected, $incoming);
        }

        return true;
    }

    /**
     * Known application_token for a portal: stored install token, else optional env secret.
     */
    private function expectedPortalApplicationToken(string $domain): string
    {
        foreach (BitrixToken::query()->get(['domain', 'application_token']) as $row) {
            if ($this->oauth->normalizeDomain((string) $row->domain) !== $domain) {
                continue;
            }
            $stored = trim((string) ($row->application_token ?? ''));
            if ($stored !== '') {
                return $stored;
            }
        }

        return match ($this->oauth->portalCode($domain)) {
            'HK' => trim((string) config('services.b24_hk.application_token', '')),
            'TR' => trim((string) (
                config('services.cleaning.application_token')
                ?: config('services.b24_tr.application_token', '')
            )),
            'AE' => trim((string) config('services.bitrix.application_token', '')),
            default => '',
        };
    }

    /**
     * Portal auth helper used by Alma Bridge fallback paths.
     */
    public function matchesDomainToken(Request $request, WebhookContext $context = WebhookContext::Bizproc): bool
    {
        [$ok] = $this->verify($request, $context);

        return $ok;
    }

    /**
     * Incoming Bitrix application_token from request payload.
     * Alma Bridge header is opt-in so Bitrix webhooks cannot be forged with it.
     */
    public function requestToken(Request $request, bool $allowAlmaHeader = false): string
    {
        $token = (string) (
            data_get($request->all(), 'auth.application_token')
            ?: $request->input('application_token', '')
            ?: $request->input('token', '')
        );

        if ($token === '' && $allowAlmaHeader) {
            $token = (string) $request->header('X-Alma-Bridge-Token', '');
        }

        return $token;
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

    /**
     * Authorize a cleaning form submit opened inside the TR local app.
     * Requires a live BX24 access_token that Bitrix accepts for this portal.
     */
    public function authorizeCleaningForm(Request $request): bool
    {
        return $this->cleaningFormUserId($request) > 0;
    }

    /**
     * Resolve Bitrix user id for a live cleaning-form BX24 access_token.
     */
    public function cleaningFormUserId(Request $request): int
    {
        $domain = $this->requestDomain($request);
        $expected = $this->oauth->normalizeDomain((string) config('services.b24_tr.portal_domain'));
        if ($domain === '' || $expected === '' || strcasecmp($domain, $expected) !== 0) {
            return 0;
        }

        $accessToken = trim((string) data_get($request->all(), 'auth.access_token', ''));
        if ($accessToken === '') {
            return 0;
        }

        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get('https://'.$domain.'/rest/user.current.json', [
                    'auth' => $accessToken,
                ]);

            if (! $response->successful()) {
                return 0;
            }

            return (int) data_get($response->json(), 'result.ID', 0);
        } catch (\Throwable) {
            return 0;
        }
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
