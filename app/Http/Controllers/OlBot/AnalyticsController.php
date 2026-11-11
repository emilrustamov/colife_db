<?php

namespace App\Http\Controllers\OlBot;

use App\Http\Controllers\Controller;
use App\Models\OlBotApartment;
use App\Models\OlBotChat;
use App\Models\OlBotMessage;
use App\Services\OlBotAnalytics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    /**
     * Chats / weekly / catalog tabs.
     */
    public function index(Request $request, OlBotAnalytics $analytics): Response
    {
        $tab = $request->string('tab')->toString() ?: 'chats';
        if (! in_array($tab, ['chats', 'weekly', 'catalog'], true)) {
            $tab = 'chats';
        }

        $page = max(1, (int) $request->input('page', 1));
        $q = $request->string('q')->toString() ?: null;

        $chats = ['data' => [], 'total' => 0];
        $weekly = [];
        $apartments = ['data' => [], 'total' => 0, 'synced_at' => null];

        if ($tab === 'chats') {
            $chats = $analytics->chats($page, 50, $q);
        } elseif ($tab === 'weekly') {
            $weekly = $analytics->weeklySummary();
        } else {
            $apartments = $analytics->apartments($page, 50, $q);
        }

        return Inertia::render('OlBot/Analytics', [
            'tab' => $tab,
            'q' => $q,
            'page' => $page,
            'chats' => $chats['data'],
            'total' => $chats['total'],
            'weekly' => $weekly,
            'apartments' => $apartments['data'],
            'apartmentsTotal' => $apartments['total'],
            'catalogSyncedAt' => $apartments['synced_at'],
            'catalogCount' => OlBotApartment::query()->count(),
        ]);
    }

    /**
     * Single chat transcript.
     */
    public function show(string $chatId): Response
    {
        $chat = OlBotChat::query()->where('chat_id', $chatId)->firstOrFail();
        $messages = OlBotMessage::query()
            ->where('chat_id', $chatId)
            ->orderBy('id')
            ->get();

        return Inertia::render('OlBot/ChatShow', [
            'chat' => array_merge($chat->toArray(), [
                'deal_url' => $chat->dealUrl(),
                'open_line_url' => $chat->openLineUrl(),
            ]),
            'messages' => $messages,
        ]);
    }
}
