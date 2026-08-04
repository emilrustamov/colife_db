<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKey
{
    /**
     * Validate X-Api-Key header against configured API key.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.client_balance.api_key', '');
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
