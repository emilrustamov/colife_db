<?php

namespace App\Http\Controllers\Api;

use App\Enums\WebhookContext;
use App\Http\Controllers\Controller;
use App\Jobs\OpenLineMessageJob;
use App\Services\BitrixOAuth;
use App\Services\TokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OpenLinesController extends Controller
{
    public function __construct(
        private readonly TokenVerifier $tokenVerifier,
        private readonly BitrixOAuth $oauth
    ) {}

    /**
     * Handle Bitrix24 Open Lines events and queue SOURCE_ID matching for HK deals.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $event = strtoupper((string) $request->input('event', ''));
        [$ok, $channel] = $this->tokenVerifier->verify($request, WebhookContext::OpenLines);

        if (! $ok) {
            Log::channel($channel)->warning('Bitrix open lines webhook rejected by token', [
                'event' => $event,
                'domain' => (string) data_get($request->all(), 'auth.domain', ''),
                'has_application_token' => (string) data_get($request->all(), 'auth.application_token', '') !== '',
            ]);

            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $domain = $this->oauth->normalizeDomain((string) data_get($request->all(), 'auth.domain', ''));
        $expectedDomain = $this->oauth->normalizeDomain((string) config('open_lines_source.portal_domain'));

        if ($domain === '' || strcasecmp($domain, $expectedDomain) !== 0) {
            Log::channel($channel)->info('Bitrix open lines webhook ignored domain', [
                'event' => $event,
                'domain' => $domain,
            ]);

            return response()->json(['success' => true, 'message' => 'Domain ignored', 'event' => $event]);
        }

        if ($event !== 'ONOPENLINEMESSAGEADD') {
            Log::channel($channel)->info('Bitrix open lines webhook ignored event', ['event' => $event]);

            return response()->json(['success' => true, 'message' => 'Event ignored', 'event' => $event]);
        }

        $rawData = data_get($request->all(), 'data.DATA');
        $packets = $this->normalizePackets($rawData);
        if ($packets === []) {
            Log::channel($channel)->warning('Bitrix open lines webhook missing data.DATA', [
                'event' => $event,
                'data_type' => get_debug_type($rawData),
                'data_keys' => is_array($rawData) ? array_keys($rawData) : null,
            ]);

            return response()->json(['success' => false, 'message' => 'Invalid payload'], 422);
        }

        $queued = 0;
        $summaries = [];
        foreach ($packets as $index => $packet) {
            $summaries[] = [
                'index' => $index,
                'chat_id' => (int) data_get($packet, 'chat.id', 0),
                'message_id' => (int) data_get($packet, 'message.id', 0),
                'message_user_id' => (int) data_get($packet, 'message.user_id', 0),
                'connector_user_id' => (int) data_get($packet, 'connector.user_id', 0),
                'line_id' => (int) data_get($packet, 'connector.line_id', 0),
                'connector_id' => (string) data_get($packet, 'connector.connector_id', ''),
                'system' => (string) data_get($packet, 'message.system', ''),
                'text_preview' => mb_substr(trim((string) data_get($packet, 'message.text', '')), 0, 80),
            ];

            OpenLineMessageJob::dispatch($domain, $packet)
                ->delay(now()->addSeconds(OpenLineMessageJob::INITIAL_DELAY_SECONDS));
            $queued++;
        }

        Log::channel($channel)->info('Bitrix open lines message batch', [
            'event' => $event,
            'domain' => $domain,
            'member_id' => (string) data_get($request->all(), 'auth.member_id', ''),
            'packets' => $summaries,
            'queued' => $queued,
        ]);

        return response()->json([
            'success' => true,
            'event' => $event,
            'packets' => count($summaries),
            'queued' => $queued,
        ]);
    }

    /**
     * Bitrix may send DATA as a list of packets or as one associative packet.
     *
     * @return list<array<string, mixed>>
     */
    private function normalizePackets(mixed $rawData): array
    {
        if (! is_array($rawData) || $rawData === []) {
            return [];
        }

        if ($this->isMessagePacket($rawData)) {
            return [$rawData];
        }

        $packets = [];
        foreach ($rawData as $item) {
            if (is_array($item) && $this->isMessagePacket($item)) {
                $packets[] = $item;
            }
        }

        return $packets;
    }

    /**
     * @param  array<string, mixed>  $packet
     */
    private function isMessagePacket(array $packet): bool
    {
        return isset($packet['message']) || isset($packet['connector']) || isset($packet['chat']);
    }
}
