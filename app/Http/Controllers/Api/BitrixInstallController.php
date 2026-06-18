<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RegisterBitrixActivityJob;
use App\Models\BitrixToken;
use App\Services\BitrixOAuthCredentialsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BitrixInstallController extends Controller
{
    public function __construct(
        private readonly BitrixOAuthCredentialsResolver $oauthCredentials
    ) {}

    /**
     * Exchange authorization code and persist OAuth tokens.
     */
    public function install(Request $request): JsonResponse
    {
        Log::info('BITRIX INSTALL', $request->all());

        $authDomain = rtrim((string) $request->input('auth.domain', ''), '/');
        $authAccessToken = (string) $request->input('auth.access_token', '');
        $authRefreshToken = (string) $request->input('auth.refresh_token', '');
        $authExpiresIn = (int) $request->input('auth.expires_in', 3600);
        $authMemberId = (string) $request->input('auth.member_id', '');
        $authUserId = $request->input('auth.user_id');

        if ($authDomain !== '' && $authAccessToken !== '' && $authRefreshToken !== '') {
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

            RegisterBitrixActivityJob::dispatch($authDomain);

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

        $accessToken = $authId;
        $refreshToken = $refreshId;
        $expiresIn = $expires > 0 ? $expires : null;

        if ($code !== '') {
            $oauth = $this->oauthCredentials->forPortalDomain($domain);
            $response = Http::asForm()->post('https://oauth.bitrix.info/oauth/token/', [
                'grant_type' => 'authorization_code',
                'client_id' => $oauth['client_id'],
                'client_secret' => $oauth['client_secret'],
                'code' => $code,
                'redirect_uri' => $oauth['redirect_uri'],
            ]);
            $response->throw();
            $data = $response->json();

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

        RegisterBitrixActivityJob::dispatch($domain);

        return response()->json([
            'status' => 'ok',
            'queued' => true,
        ]);
    }
}
