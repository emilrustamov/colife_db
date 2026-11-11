<?php

namespace App\Http\Controllers\OlBot;

use App\Http\Controllers\Controller;
use App\Models\OlBotChat;
use App\Models\OlBotMessage;
use App\Services\OlBotAnalytics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    /**
     * Chats table + weekly summary tabs.
     */
    public function index(Request $request, OlBotAnalytics $analytics): Response
    {
        $tab = $request->string('tab')->toString() ?: 'chats';
        $page = max(1, (int) $request->input('page', 1));
        $q = $request->string('q')->toString() ?: null;
        $chats = $analytics->chats($page, 50, $q);
        $weekly = $tab === 'weekly' ? $analytics->weeklySummary() : [];

        return Inertia::render('OlBot/Analytics', [
            'tab' => $tab,
            'q' => $q,
            'page' => $page,
            'chats' => $chats['data'],
            'total' => $chats['total'],
            'weekly' => $weekly,
            'catalogCount' => \App\Models\OlBotApartment::query()->count(),
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
            'chat' => $chat,
            'messages' => $messages,
        ]);
    }
}
