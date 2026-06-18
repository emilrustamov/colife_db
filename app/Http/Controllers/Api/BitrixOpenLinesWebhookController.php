<?php

namespace App\Http\Controllers\Api;

use App\Enums\BitrixOutgoingWebhookContext;
use App\Http\Controllers\Controller;
use App\Services\BitrixOutgoingApplicationTokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BitrixOpenLinesWebhookController extends Controller
{
    public function __construct(
        private readonly BitrixOutgoingApplicationTokenVerifier $tokenVerifier
    ) {}

    /**
     * Handle Bitrix24 outgoing events for open lines (e.g. OnOpenLineMessageAdd).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $event = strtoupper((string) $request->input('event', ''));
        [$ok, $channel] = $this->tokenVerifier->verify($request, BitrixOutgoingWebhookContext::OpenLines);

        if (! $ok) {
            Log::channel($channel)->warning('Bitrix open lines webhook rejected by token', [
                'event' => $event,
                'domain' => (string) data_get($request->all(), 'auth.domain', ''),
                'has_application_token' => (string) data_get($request->all(), 'auth.application_token', '') !== '',
            ]);

            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        if ($event !== 'ONOPENLINEMESSAGEADD') {
            Log::channel($channel)->info('Bitrix open lines webhook ignored event', ['event' => $event]);

            return response()->json(['success' => true, 'message' => 'Event ignored', 'event' => $event]);
        }

        $packets = data_get($request->all(), 'data.DATA');
        if (! is_array($packets)) {
            Log::channel($channel)->warning('Bitrix open lines webhook missing data.DATA', [
                'event' => $event,
            ]);

            return response()->json(['success' => false, 'message' => 'Invalid payload'], 422);
        }

        $summaries = [];
        foreach ($packets as $index => $packet) {
            if (! is_array($packet)) {
                continue;
            }
            $summaries[] = [
                'index' => $index,
                'chat_id' => (int) data_get($packet, 'chat.id', 0),
                'message_id' => (int) data_get($packet, 'message.id', 0),
                'line_id' => (int) data_get($packet, 'connector.line_id', 0),
                'connector_id' => (string) data_get($packet, 'connector.connector_id', ''),
                'system' => (string) data_get($packet, 'message.system', ''),
            ];
        }

        Log::channel($channel)->info('Bitrix open lines message batch', [
            'event' => $event,
            'domain' => (string) data_get($request->all(), 'auth.domain', ''),
            'member_id' => (string) data_get($request->all(), 'auth.member_id', ''),
            'packets' => $summaries,
        ]);

        return response()->json([
            'success' => true,
            'event' => $event,
            'packets' => count($summaries),
        ]);
    }
}
