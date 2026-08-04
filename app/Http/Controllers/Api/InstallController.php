<?php

namespace App\Http\Controllers\Api;

use App\Enums\WebhookContext;
use App\Http\Controllers\Controller;
use App\Jobs\RegisterActivityJob;
use App\Models\BitrixToken;
use App\Services\BitrixAuth;
use App\Services\TokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InstallController extends Controller
{
    public function __construct(
        private readonly BitrixAuth $bitrixAuthService,
        private readonly TokenVerifier $tokenVerifier
    ) {}

    /**
     * Exchange authorization code and persist OAuth tokens.
     */
    public function install(Request $request): JsonResponse
    {
        [$tokenOk] = $this->tokenVerifier->verify($request, WebhookContext::Crm);
        if (! $tokenOk) {
            Log::warning('BITRIX_INSTALL_REJECTED', [
                'domain' => data_get($request->all(), 'auth.domain') ?: $request->input('DOMAIN'),
                'has_application_token' => (string) data_get($request->all(), 'auth.application_token', '') !== '',
            ]);

            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $authDomain = rtrim((string) $request->input('auth.domain', ''), '/');
        $authAccessToken = (string) $request->input('auth.access_token', '');
        $authRefreshToken = (string) $request->input('auth.refresh_token', '');
        $authExpiresIn = (int) $request->input('auth.expires_in', 3600);
        $authMemberId = (string) $request->input('auth.member_id', '');
        $authUserId = $request->input('auth.user_id');

        Log::info('BITRIX_INSTALL', [
            'domain' => $authDomain !== '' ? $authDomain : $request->input('DOMAIN'),
            'member_id' => $authMemberId !== '' ? $authMemberId : null,
            'user_id' => is_numeric($authUserId) ? (int) $authUserId : null,
            'has_access_token' => $authAccessToken !== '',
            'has_refresh_token' => $authRefreshToken !== '',
            'has_code' => (string) $request->get('CODE', '') !== '',
        ]);

        $allowedDomain = rtrim((string) config('services.bitrix.portal_domain', ''), '/');

        if ($authDomain !== '' && $authAccessToken !== '' && $authRefreshToken !== '') {
            if ($allowedDomain !== '' && ! hash_equals(strtolower($allowedDomain), strtolower($authDomain))) {
                return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
            }

            BitrixToken::query()->updateOrCreate(
                ['domain' => $authDomain],
                [
                    'access_token' => $authAccessToken,
                    'auth_id' => $authAccessToken,
                    'refresh_token' => $authRefreshToken,
                    'refresh_id' => $authRefreshToken,
                    'expires_in' => $authExpiresIn,
                    'expires_at' => now()->addSeconds($authExpiresIn),
                    'member_id' => $authMemberId !== '' ? $authMemberId : null,
                    'user_id' => is_numeric($authUserId) ? (int) $authUserId : null,
                ]
            );

            RegisterActivityJob::dispatch($authDomain);

            return response()->json([
                'status' => 'ok',
                'queued' => true,
            ]);
        }

        $code = (string) $request->get('CODE', '');
        $domain = rtrim((string) $request->input('DOMAIN', ''), '/');
        $authId = (string) $request->input('AUTH_ID', '');
        $refreshId = (string) $request->input('REFRESH_ID', '');
        $expires = (int) $request->input('AUTH_EXPIRES', 0);

        if ($domain === '') {
            return response()->json(['status' => 'error', 'message' => 'DOMAIN is required'], 422);
        }

        if ($allowedDomain !== '' && ! hash_equals(strtolower($allowedDomain), strtolower($domain))) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

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
            return response()->json(['status' => 'error', 'message' => 'Access and refresh tokens are required'], 422);
        }

        BitrixToken::query()->updateOrCreate(
            ['domain' => $domain],
            [
                'access_token' => $accessToken,
                'auth_id' => $accessToken,
                'refresh_token' => $refreshToken,
                'refresh_id' => $refreshToken,
                'expires_in' => $expiresIn,
                'expires_at' => is_int($expiresIn) && $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
                'member_id' => null,
                'user_id' => null,
            ]
        );

        RegisterActivityJob::dispatch($domain);

        return response()->json([
            'status' => 'ok',
            'queued' => true,
        ]);
    }
}
