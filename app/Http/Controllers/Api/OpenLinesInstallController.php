<?php

namespace App\Http\Controllers\Api;

use App\Enums\WebhookContext;
use App\Http\Controllers\Controller;
use App\Jobs\RegisterOlEventsJob;
use App\Models\BitrixToken;
use App\Services\BitrixAuth;
use App\Services\BitrixOAuth;
use App\Services\TokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class OpenLinesInstallController extends Controller
{
    public function __construct(
        private readonly BitrixAuth $bitrixAuthService,
        private readonly BitrixOAuth $oauth,
        private readonly TokenVerifier $tokenVerifier,
    ) {}

    /**
     * Show Bitrix local-app install page for Open Lines source automation (HK only).
     */
    public function install(Request $request): View
    {
        $logger = Log::channel('bitrix_open_lines');
        $payload = $request->all();
        $domain = $this->oauth->normalizeDomain(
            (string) (data_get($payload, 'auth.domain') ?: $request->input('DOMAIN', ''))
        );
        $expectedDomain = $this->oauth->normalizeDomain((string) config('open_lines_source.portal_domain'));
        [$tokenOk] = $this->tokenVerifier->verify($request, WebhookContext::OpenLines);
        $domainAllowed = $domain !== '' && strcasecmp($domain, $expectedDomain) === 0;

        $logger->info('OL_INSTALL_HIT', [
            'domain' => $domain !== '' ? $domain : null,
            'keys' => array_keys($payload),
            'has_auth' => is_array(data_get($payload, 'auth')),
            'domain_allowed' => $domainAllowed,
            'token_ok' => $tokenOk,
        ]);

        $saved = false;
        $message = 'Finishing installation…';

        if ($domainAllowed && $tokenOk) {
            $saved = $this->persistFromRequest($request, $domain);
            if ($saved) {
                RegisterOlEventsJob::dispatchSync($domain);
                $message = 'Open Lines Source app installed.';
            }
        } elseif ($domain !== '' && ! $domainAllowed) {
            $message = 'Portal domain is not allowed: '.$domain;
            $logger->warning('OL_INSTALL_DOMAIN_REJECTED', ['domain' => $domain]);
        } elseif ($domainAllowed && ! $tokenOk) {
            $logger->warning('OL_INSTALL_TOKEN_PENDING', ['domain' => $domain]);
        }

        return view('open_lines.install', [
            'ok' => $domainAllowed || $domain === '',
            'saved' => $saved,
            'message' => $message,
            'saveUrl' => url('/api/open-lines/install/save'),
        ]);
    }

    /**
     * Persist OAuth tokens posted from BX24.getAuth() during install.
     */
    public function save(Request $request): JsonResponse
    {
        $logger = Log::channel('bitrix_open_lines');
        [$tokenOk] = $this->tokenVerifier->verify($request, WebhookContext::OpenLines);
        if (! $tokenOk) {
            $logger->warning('OL_INSTALL_SAVE_REJECTED', [
                'domain' => $request->input('domain') ?: data_get($request->all(), 'auth.domain'),
            ]);

            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $domain = $this->oauth->normalizeDomain(
            (string) ($request->input('domain') ?: data_get($request->all(), 'auth.domain', ''))
        );

        $accessToken = (string) ($request->input('access_token') ?: data_get($request->all(), 'auth.access_token', ''));
        $refreshToken = (string) ($request->input('refresh_token') ?: data_get($request->all(), 'auth.refresh_token', ''));
        $expiresIn = (int) ($request->input('expires_in') ?: data_get($request->all(), 'auth.expires_in', 3600));
        $memberId = (string) ($request->input('member_id') ?: data_get($request->all(), 'auth.member_id', ''));
        $userId = $request->input('user_id', data_get($request->all(), 'auth.user_id'));
        $applicationToken = (string) ($request->input('application_token') ?: data_get($request->all(), 'auth.application_token', ''));

        if ($accessToken === '' || $refreshToken === '') {
            return response()->json(['status' => 'error', 'message' => 'Tokens required'], 422);
        }

        $attributes = [
            'access_token' => $accessToken,
            'auth_id' => $accessToken,
            'refresh_token' => $refreshToken,
            'refresh_id' => $refreshToken,
            'expires_in' => $expiresIn > 0 ? $expiresIn : 3600,
            'expires_at' => now()->addSeconds($expiresIn > 0 ? $expiresIn : 3600),
            'member_id' => $memberId !== '' ? $memberId : null,
            'user_id' => is_numeric($userId) ? (int) $userId : null,
        ];
        if ($applicationToken !== '') {
            $attributes['application_token'] = $applicationToken;
        }

        BitrixToken::query()->updateOrCreate(
            ['domain' => $domain],
            $attributes
        );

        RegisterOlEventsJob::dispatchSync($domain);

        $logger->info('OL_INSTALL_SAVE_OK', [
            'domain' => $domain,
            'user_id' => is_numeric($userId) ? (int) $userId : null,
            'has_application_token' => $applicationToken !== '',
            'application_token_hint' => $applicationToken !== '' ? substr($applicationToken, 0, 8).'…' : null,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Persist tokens from classic Bitrix install POST fields when available.
     */
    private function persistFromRequest(Request $request, string $domain): bool
    {
        $applicationToken = (string) data_get($request->all(), 'auth.application_token', '');
        $authAccessToken = (string) $request->input('auth.access_token', '');
        $authRefreshToken = (string) $request->input('auth.refresh_token', '');
        $authExpiresIn = (int) $request->input('auth.expires_in', 3600);
        $authMemberId = (string) $request->input('auth.member_id', '');
        $authUserId = $request->input('auth.user_id');

        if ($authAccessToken !== '' && $authRefreshToken !== '') {
            $attributes = [
                'access_token' => $authAccessToken,
                'auth_id' => $authAccessToken,
                'refresh_token' => $authRefreshToken,
                'refresh_id' => $authRefreshToken,
                'expires_in' => $authExpiresIn,
                'expires_at' => now()->addSeconds($authExpiresIn > 0 ? $authExpiresIn : 3600),
                'member_id' => $authMemberId !== '' ? $authMemberId : null,
                'user_id' => is_numeric($authUserId) ? (int) $authUserId : null,
            ];
            if ($applicationToken !== '') {
                $attributes['application_token'] = $applicationToken;
            }

            BitrixToken::query()->updateOrCreate(
                ['domain' => $domain],
                $attributes
            );

            return true;
        }

        $code = (string) $request->get('CODE', '');
        $authId = (string) $request->input('AUTH_ID', '');
        $refreshId = (string) $request->input('REFRESH_ID', '');
        $expires = (int) $request->input('AUTH_EXPIRES', 0);

        $accessToken = $authId;
        $refreshToken = $refreshId;
        $expiresIn = $expires > 0 ? $expires : null;

        if ($code !== '') {
            $data = $this->bitrixAuthService->exchangeCode($code, $domain);
            $accessToken = (string) ($data['access_token'] ?? '');
            $refreshToken = (string) ($data['refresh_token'] ?? '');
            $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : null;
        }

        if ($accessToken === '' || $refreshToken === '') {
            return false;
        }

        $attributes = [
            'access_token' => $accessToken,
            'auth_id' => $accessToken,
            'refresh_token' => $refreshToken,
            'refresh_id' => $refreshToken,
            'expires_in' => $expiresIn,
            'expires_at' => is_int($expiresIn) && $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
            'member_id' => null,
            'user_id' => null,
        ];
        if ($applicationToken !== '') {
            $attributes['application_token'] = $applicationToken;
        }

        BitrixToken::query()->updateOrCreate(
            ['domain' => $domain],
            $attributes
        );

        return true;
    }
}
