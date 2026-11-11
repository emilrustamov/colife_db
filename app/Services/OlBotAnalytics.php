<?php

namespace App\Services;

use App\Models\OlBotApartment;
use App\Models\OlBotChat;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OlBotAnalytics
{
    /**
     * Weekly summary rows (Monday start).
     *
     * @return list<array<string, mixed>>
     */
    public function weeklySummary(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = ($from ?? now()->subWeeks(12)->startOfWeek(Carbon::MONDAY))->startOfDay();
        $to = ($to ?? now()->endOfWeek(Carbon::SUNDAY))->endOfDay();

        /** @var Collection<int, object> $rows */
        $rows = DB::table('ol_bot_chats')
            ->selectRaw('YEARWEEK(started_at, 3) as yw')
            ->selectRaw('DATE(DATE_SUB(DATE(started_at), INTERVAL WEEKDAY(started_at) DAY)) as week_start')
            ->selectRaw('COUNT(*) as chats')
            ->selectRaw('SUM(messages_count) as messages')
            ->selectRaw('AVG(duration_sec) as avg_duration_sec')
            ->selectRaw('AVG(first_response_sec) as avg_first_response_sec')
            ->selectRaw('AVG(avg_response_sec) as avg_response_sec')
            ->selectRaw('AVG(max_response_sec) as avg_max_response_sec')
            ->selectRaw('SUM(escalated) as escalated')
            ->selectRaw('SUM(credits) as credits')
            ->whereNotNull('started_at')
            ->whereBetween('started_at', [$from, $to])
            ->groupBy('yw', 'week_start')
            ->orderByDesc('week_start')
            ->get();

        $buckets = DB::table('ol_bot_chats')
            ->selectRaw('DATE(DATE_SUB(DATE(started_at), INTERVAL WEEKDAY(started_at) DAY)) as week_start')
            ->selectRaw('escalate_bucket')
            ->selectRaw('COUNT(*) as cnt')
            ->where('escalated', 1)
            ->whereNotNull('started_at')
            ->whereBetween('started_at', [$from, $to])
            ->groupBy('week_start', 'escalate_bucket')
            ->get()
            ->groupBy('week_start');

        return $rows->map(function (object $row) use ($buckets) {
            $weekBuckets = $buckets->get($row->week_start, collect());
            $byBucket = [
                'unknown' => 0,
                'user_requested' => 0,
                'rule' => 0,
            ];
            foreach ($weekBuckets as $b) {
                $key = $b->escalate_bucket ?: 'rule';
                if (! array_key_exists($key, $byBucket)) {
                    $key = 'rule';
                }
                $byBucket[$key] += (int) $b->cnt;
            }

            $chats = (int) $row->chats;
            $escalated = (int) $row->escalated;

            return [
                'week_start' => (string) $row->week_start,
                'chats' => $chats,
                'messages' => (int) $row->messages,
                'avg_duration_sec' => $row->avg_duration_sec !== null ? (int) round((float) $row->avg_duration_sec) : null,
                'avg_first_response_sec' => $row->avg_first_response_sec !== null ? (int) round((float) $row->avg_first_response_sec) : null,
                'avg_response_sec' => $row->avg_response_sec !== null ? (int) round((float) $row->avg_response_sec) : null,
                'avg_max_response_sec' => $row->avg_max_response_sec !== null ? (int) round((float) $row->avg_max_response_sec) : null,
                'escalated' => $escalated,
                'escalated_pct' => $chats > 0 ? round($escalated * 100 / $chats, 1) : 0,
                'escalate_unknown' => $byBucket['unknown'],
                'escalate_user_requested' => $byBucket['user_requested'],
                'escalate_rule' => $byBucket['rule'],
                'credits' => round((float) $row->credits, 3),
            ];
        })->all();
    }

    /**
     * Paginated chat list for analytics table.
     *
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function chats(int $page = 1, int $perPage = 50, ?string $q = null): array
    {
        $query = OlBotChat::query()->orderByDesc('started_at');
        if ($q) {
            $query->where(function ($builder) use ($q) {
                $builder->where('chat_id', 'like', '%'.$q.'%')
                    ->orWhere('dialog_id', 'like', '%'.$q.'%')
                    ->orWhere('deal_id', 'like', '%'.$q.'%')
                    ->orWhere('tags', 'like', '%'.$q.'%')
                    ->orWhere('category', 'like', '%'.$q.'%')
                    ->orWhere('escalate_reason', 'like', '%'.$q.'%');
            });
        }
        $total = (clone $query)->count();
        $data = $query->forPage($page, $perPage)->get()->map(function (OlBotChat $chat) {
            return array_merge($chat->toArray(), [
                'deal_url' => $chat->dealUrl(),
                'open_line_url' => $chat->openLineUrl(),
            ]);
        });

        return ['data' => $data->all(), 'total' => $total];
    }

    /**
     * Paginated apartment catalog snapshot used by the bot.
     *
     * @return array{data: list<OlBotApartment>, total: int, synced_at: string|null}
     */
    public function apartments(int $page = 1, int $perPage = 50, ?string $q = null): array
    {
        $query = OlBotApartment::query()->orderBy('area')->orderBy('title');
        if ($q) {
            $like = '%'.$q.'%';
            $query->where(function ($builder) use ($like) {
                $builder->where('title', 'like', $like)
                    ->orWhere('uid', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('unit_number', 'like', $like)
                    ->orWhere('area', 'like', $like)
                    ->orWhere('type', 'like', $like)
                    ->orWhere('category', 'like', $like)
                    ->orWhere('rent_type', 'like', $like);
            });
        }

        $total = (clone $query)->count();
        $data = $query->forPage($page, $perPage)->get([
            'id',
            'uid',
            'title',
            'sku',
            'unit_number',
            'link',
            'area',
            'type',
            'capacity',
            'available_from',
            'rent_type',
            'category',
            'price_unit',
            'price',
            'synced_at',
        ]);

        $syncedAt = OlBotApartment::query()->max('synced_at');

        return [
            'data' => $data->all(),
            'total' => $total,
            'synced_at' => $syncedAt ? (string) $syncedAt : null,
        ];
    }
}
