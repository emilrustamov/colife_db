<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleSheetsApi
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const SHEETS_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    public const SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

    /**
     * List worksheet titles for a spreadsheet.
     *
     * @return list<string>
     */
    public function sheetTitles(string $spreadsheetId): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout((int) config('services.landlord_finance.timeout', 60))
            ->get(self::SHEETS_BASE.'/'.$spreadsheetId, [
                'fields' => 'sheets.properties.title',
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Google Sheets metadata failed ('.$response->status().'): '.$response->body()
            );
        }

        $titles = [];
        foreach ($response->json('sheets') ?? [] as $sheet) {
            $title = trim((string) data_get($sheet, 'properties.title', ''));
            if ($title !== '') {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    /**
     * Read a values range (A1 notation, sheet name may be quoted).
     *
     * @return list<list<mixed>>
     */
    public function values(string $spreadsheetId, string $range): array
    {
        $encodedRange = rawurlencode($range);
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout((int) config('services.landlord_finance.timeout', 60))
            ->get(self::SHEETS_BASE.'/'.$spreadsheetId.'/values/'.$encodedRange, [
                'valueRenderOption' => 'UNFORMATTED_VALUE',
                'dateTimeRenderOption' => 'FORMATTED_STRING',
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Google Sheets values failed ('.$response->status().'): '.$response->body()
            );
        }

        $values = $response->json('values');
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(
            static fn ($row) => is_array($row) ? array_values($row) : [],
            $values
        ));
    }

    /**
     * Build Google OAuth consent URL for installed-app flow.
     */
    public function oauthAuthUrl(): string
    {
        $client = $this->oauthClient();

        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $client['client_id'],
            'redirect_uri' => $client['redirect_uri'],
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ]);
    }

    /**
     * Exchange authorization code for tokens and persist refresh token.
     *
     * @return array<string, mixed>
     */
    public function exchangeOauthCode(string $code): array
    {
        $client = $this->oauthClient();
        $response = Http::asForm()
            ->timeout(30)
            ->post(self::TOKEN_URL, [
                'code' => $code,
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
                'redirect_uri' => $client['redirect_uri'],
                'grant_type' => 'authorization_code',
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Google OAuth code exchange failed ('.$response->status().'): '.$response->body()
            );
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();
        if (! is_array($payload) || empty($payload['refresh_token'])) {
            throw new RuntimeException(
                'Google OAuth did not return refresh_token. Revoke prior access and retry with prompt=consent.'
            );
        }

        $this->storeOauthToken($payload);
        Cache::forget('google_sheets_oauth_access_token');

        return $payload;
    }

    /**
     * Obtain access token via OAuth refresh token or service account.
     */
    private function accessToken(): string
    {
        $mode = strtolower(trim((string) config('services.landlord_finance.auth', 'oauth')));

        return $mode === 'sa' ? $this->serviceAccountAccessToken() : $this->oauthAccessToken();
    }

    /**
     * Refresh OAuth user access token.
     */
    private function oauthAccessToken(): string
    {
        $cacheKey = 'google_sheets_oauth_access_token';
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->oauthToken();
        $refreshToken = trim((string) ($token['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            throw new RuntimeException(
                'Google OAuth refresh token is missing. Run: php artisan landlord-finance:google-auth'
            );
        }

        $client = $this->oauthClient();
        $response = Http::asForm()
            ->timeout(30)
            ->post(self::TOKEN_URL, [
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Google OAuth refresh failed ('.$response->status().'): '.$response->body()
            );
        }

        $accessToken = trim((string) $response->json('access_token'));
        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        if ($accessToken === '') {
            throw new RuntimeException('Google OAuth refresh returned empty access_token.');
        }

        $newRefresh = trim((string) ($response->json('refresh_token') ?? ''));
        if ($newRefresh !== '') {
            $token['refresh_token'] = $newRefresh;
            $this->storeOauthToken($token);
        }

        Cache::put($cacheKey, $accessToken, max(60, $expiresIn - 60));

        return $accessToken;
    }

    /**
     * Obtain a cached service-account access token.
     */
    private function serviceAccountAccessToken(): string
    {
        $cacheKey = 'google_sheets_sa_access_token';
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $credentials = $this->serviceAccountCredentials();
        $now = time();
        $jwt = $this->makeJwt($credentials, $now);

        $response = Http::asForm()
            ->timeout(30)
            ->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Google SA token failed ('.$response->status().'): '.$response->body()
            );
        }

        $token = trim((string) $response->json('access_token'));
        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        if ($token === '') {
            throw new RuntimeException('Google SA token response is empty.');
        }

        Cache::put($cacheKey, $token, max(60, $expiresIn - 60));

        return $token;
    }

    /**
     * @return array{client_id: string, client_secret: string, redirect_uri: string}
     */
    private function oauthClient(): array
    {
        $path = (string) config('services.landlord_finance.oauth_client_path');
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException('Google OAuth client JSON is missing.');
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json)) {
            throw new RuntimeException('Google OAuth client JSON is invalid.');
        }

        $block = $json['installed'] ?? $json['web'] ?? $json;
        if (! is_array($block)) {
            throw new RuntimeException('Google OAuth client JSON has no installed/web block.');
        }

        $clientId = trim((string) ($block['client_id'] ?? ''));
        $clientSecret = trim((string) ($block['client_secret'] ?? ''));
        $redirect = '';
        if (isset($block['redirect_uris']) && is_array($block['redirect_uris']) && $block['redirect_uris'] !== []) {
            $redirect = trim((string) $block['redirect_uris'][0]);
        }
        if ($redirect === '') {
            $redirect = 'http://localhost';
        }

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Google OAuth client_id/client_secret are incomplete.');
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirect,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function oauthToken(): array
    {
        $path = (string) config('services.landlord_finance.oauth_token_path');
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException(
                'Google OAuth token file is missing. Run: php artisan landlord-finance:google-auth'
            );
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json)) {
            throw new RuntimeException('Google OAuth token JSON is invalid.');
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeOauthToken(array $payload): void
    {
        $path = (string) config('services.landlord_finance.oauth_token_path');
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create Google OAuth token directory.');
        }

        $existing = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        if (! is_array($existing)) {
            $existing = [];
        }

        $merged = array_merge($existing, $payload);
        $merged['saved_at'] = now()->toIso8601String();

        if (file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n") === false) {
            throw new RuntimeException('Unable to write Google OAuth token file.');
        }
        chmod($path, 0600);
    }

    /**
     * @return array{client_email: string, private_key: string}
     */
    private function serviceAccountCredentials(): array
    {
        $path = (string) config('services.landlord_finance.credentials_path');
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException('Google service account credentials file is missing.');
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json)) {
            throw new RuntimeException('Google service account credentials JSON is invalid.');
        }

        $email = trim((string) ($json['client_email'] ?? ''));
        $privateKey = (string) ($json['private_key'] ?? '');
        if ($email === '' || $privateKey === '') {
            throw new RuntimeException('Google service account credentials are incomplete.');
        }

        return [
            'client_email' => $email,
            'private_key' => $privateKey,
        ];
    }

    /**
     * @param  array{client_email: string, private_key: string}  $credentials
     */
    private function makeJwt(array $credentials, int $now): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));

        $unsigned = $header.'.'.$claims;
        $privateKey = openssl_pkey_get_private($credentials['private_key']);
        if ($privateKey === false) {
            throw new RuntimeException('Unable to load Google service account private key.');
        }

        $signature = '';
        $ok = openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (! $ok) {
            throw new RuntimeException('Unable to sign Google service account JWT.');
        }

        return $unsigned.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * Base64 URL-safe encode without padding.
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
