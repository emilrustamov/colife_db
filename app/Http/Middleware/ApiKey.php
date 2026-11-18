<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKey
{
    /**
     * Validate X-Api-Key header against a configured API key.
     *
     * Usage:
     * - middleware('api.key') → CLIENT_BALANCE_API_KEY
     * - middleware('api.key:alma_bridge') → COLIFE_API_KEY (Postman {{colifeApiKey}})
     */
    public function handle(Request $request, Closure $next, string $name = 'client_balance'): Response
    {
        $expected = match ($name) {
            'alma_bridge' => (string) config('alma_bridge.api_key', ''),
            default => (string) config('services.client_balance.api_key', ''),
        };
        $apiKey = (string) $request->header('X-Api-Key', '');

        if ($expected === '' || $apiKey === '' || ! hash_equals($expected, $apiKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        return $next($request);
    }
}
