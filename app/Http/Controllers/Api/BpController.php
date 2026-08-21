<?php

namespace App\Http\Controllers\Api;

use App\Enums\WebhookContext;
use App\Http\Controllers\Controller;
use App\Jobs\PauseJob;
use App\Services\BitrixOAuth;
use App\Services\PauseDates;
use App\Services\TokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BpController extends Controller
{
    public function __construct(
        private readonly PauseDates $pauseDateResolver,
        private readonly TokenVerifier $tokenVerifier,
        private readonly BitrixOAuth $oauth
    ) {}

    /**
     * Handle Bitrix bizproc wait activity callback.
     */
    public function wait(Request $request): JsonResponse
    {
        $logger = Log::channel('bitrix_pauses');

        [$tokenOk] = $this->tokenVerifier->verify($request, WebhookContext::Bizproc);
        if (! $tokenOk) {
            $logger->warning('BP_WAIT_REJECTED', [
                'workflow_id' => $request->input('workflow_id'),
                'domain' => data_get($request->all(), 'auth.domain'),
            ]);

            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $payload = $request->all();
        $eventToken = (string) ($payload['event_token'] ?? '');
        $eventTokenFingerprint = $eventToken !== '' ? substr(hash('sha256', $eventToken), 0, 12) : null;
        $domain = $this->oauth->normalizeDomain((string) $request->input('auth.domain', ''));

        $logger->info('BP_WAIT_RECEIVED', [
            'workflow_id' => $payload['workflow_id'] ?? null,
            'code' => $payload['code'] ?? null,
            'document_id' => $payload['document_id'] ?? null,
            'event_token_fp' => $eventTokenFingerprint,
            'domain' => $domain,
            'properties' => $payload['properties'] ?? null,
        ]);

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
        $enqueuedAt = now()->toIso8601String();

        PauseJob::dispatch([
            'event_token' => $eventToken,
            'domain' => $domain,
            'workflow_id' => (string) ($payload['workflow_id'] ?? ''),
            'document_id' => $payload['document_id'] ?? null,
            'enqueued_at' => $enqueuedAt,
            'target_at' => $targetAt->toIso8601String(),
            'wait_seconds' => $delay,
        ])->onQueue('bp-pauses')->delay($targetAt);

        $logger->info('PAUSE_ENQUEUED', [
            'domain' => $domain,
            'event_token_fp' => $eventTokenFingerprint,
            'workflow_id' => $payload['workflow_id'] ?? null,
            'document_id' => $payload['document_id'] ?? null,
            'wait' => $delay,
            'mode' => $mode,
            'date_raw' => $props['date'] ?? null,
            'seconds_raw' => $props['seconds'] ?? null,
            'timezone' => $timezone,
            'enqueued_at' => $enqueuedAt,
            'target_at_portal' => $targetAt->copy()->timezone($timezone)->toIso8601String(),
        ]);

        return response()->json([
            'status' => 'queued',
            'wait' => $delay,
        ]);
    }
}
