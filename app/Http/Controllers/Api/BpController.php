<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPauseJob;
use App\Services\BitrixPauseDateResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BpController extends Controller
{
    public function __construct(
        private readonly BitrixPauseDateResolver $pauseDateResolver
    ) {}

    /**
     * Handle Bitrix bizproc wait activity callback.
     */
    public function wait(Request $request): JsonResponse
    {
        Log::info('BP WAIT', $request->all());

        $domain = rtrim((string) $request->input('auth.domain', ''), '/');
        if ($domain === '') {
            return response()->json(['status' => 'error', 'message' => 'domain is required'], 422);
        }

        $eventToken = (string) $request->input('event_token', '');
        if ($eventToken === '') {
            return response()->json(['status' => 'error', 'message' => 'event_token is required'], 422);
        }

        $props = $request->input('properties', []);
        if (! is_array($props)) {
            $props = [];
        }

        $mode = (string) ($props['mode'] ?? 'seconds');
        if (! in_array($mode, ['seconds', 'date'], true)) {
            $mode = 'seconds';
        }

        $delay = $mode === 'date'
            ? $this->pauseDateResolver->resolveDelaySeconds($props['date'] ?? null)
            : $this->pauseDateResolver->resolveSecondsDelay($props['seconds'] ?? null);

        $timezone = $this->pauseDateResolver->portalTimezone();
        $targetAt = now()->addSeconds($delay);

        ProcessPauseJob::dispatch([
            'event_token' => $eventToken,
            'domain' => $domain,
        ])->onQueue('bp-pauses')->delay($targetAt);

        Log::info('PAUSE_ENQUEUED', [
            'domain' => $domain,
            'event_token' => $eventToken,
            'wait' => $delay,
            'mode' => $mode,
            'date_raw' => $props['date'] ?? null,
            'seconds_raw' => $props['seconds'] ?? null,
            'timezone' => $timezone,
            'target_at_portal' => $targetAt->copy()->timezone($timezone)->toIso8601String(),
        ]);

        return response()->json([
            'status' => 'queued',
            'wait' => $delay,
        ]);
    }
}
