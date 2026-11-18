<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ApiKey
{
    /**
     * Validate API key against configured secret.
     *
     * Usage:
     * - middleware('api.key') → CLIENT_BALANCE_API_KEY via X-Api-Key
     * - middleware('api.key:alma_bridge') → COLIFE_API_KEY via X-Api-Key
     *   or body/query `api_key` / `colifeApiKey`
     *   (Bitrix BP webhook often sends JSON as text/plain — we merge raw JSON)
     */
    public function handle(Request $request, Closure $next, string $name = 'client_balance'): Response
    {
        if ($name === 'alma_bridge') {
            $this->mergeRawJsonBody($request);
        }

        $expected = match ($name) {
            'alma_bridge' => (string) config('alma_bridge.api_key', ''),
            default => (string) config('services.client_balance.api_key', ''),
        };

        $apiKey = (string) $request->header('X-Api-Key', '');
        if ($apiKey === '' && $name === 'alma_bridge') {
            $apiKey = (string) (
                $request->input('api_key')
                ?: $request->input('colifeApiKey')
                ?: ''
            );
        }

        if ($expected === '' || $apiKey === '' || ! hash_equals($expected, $apiKey)) {
            if ($name === 'alma_bridge') {
                $raw = (string) $request->getContent();
                $payload = $request->input('payload');
                Log::channel('alma_bridge')->warning('Alma bridge API key rejected', [
                    'path' => $request->path(),
                    'content_type' => (string) $request->header('Content-Type', ''),
                    'ua' => (string) $request->userAgent(),
                    'has_header_key' => $request->header('X-Api-Key') !== null,
                    'input_keys' => array_keys($request->all()),
                    'id' => $request->input('id'),
                    'payload_prefix' => is_string($payload)
                        ? substr(preg_replace('/[^\x20-\x7E]/', '.', $payload) ?? '', 0, 120)
                        : null,
                    'raw_len' => strlen($raw),
                    'raw_prefix' => substr(preg_replace('/[^\x20-\x7E]/', '.', $raw) ?? '', 0, 120),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        return $next($request);
    }

    /**
     * Normalize Bitrix BP bodies into request input:
     * - raw JSON as text/plain (optional UTF-8 BOM)
     * - form field `payload` with JSON (outgoing webhook / BP HTTP)
     * - lists document_id → id when id is missing
     */
    private function mergeRawJsonBody(Request $request): void
    {
        $payload = $request->input('payload');
        if (is_array($payload)) {
            // Bitrix BP form: payload[id], payload[api_key], ...
            $request->merge($payload);
        } elseif (is_string($payload) && trim($payload) !== '') {
            $fromPayload = $this->decodeJsonObject($payload);
            if ($fromPayload !== null) {
                $request->merge($fromPayload);
            }
        }

        if (! ($request->isJson() && $request->request->count() > 0)) {
            $raw = (string) $request->getContent();
            $decoded = $this->decodeJsonObject($raw);
            if ($decoded !== null) {
                $request->merge($decoded);
            }
        }

        if ($request->filled('id')) {
            return;
        }

        $documentId = $request->input('document_id');
        if (is_array($documentId) && $documentId !== []) {
            $last = end($documentId);
            if (is_scalar($last) && (string) $last !== '') {
                $request->merge(['id' => (string) $last]);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(string $raw): ?array
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if ($raw[0] === '"') {
            $unquoted = json_decode($raw, true);
            if (is_string($unquoted)) {
                $raw = trim($unquoted);
            }
        }

        if ($raw === '' || ($raw[0] !== '{' && $raw[0] !== '[')) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
