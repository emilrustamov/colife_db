<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OlBotAlaioQuota
{
    private const PERIOD_DAYS = [
        'day' => 1,
        'week' => 7,
        'month' => 30,
    ];

    private const FREE_MODELS = [
        'bitrix/bitrixgpt-5.5',
        'bitrix/bitrixgpt-5.5-thinking',
        'bitrix/embeddings',
    ];

    private const AGENT_PROMPT_PER_M = 0.889;

    private const AGENT_COMPLETION_PER_M = 4.446;

    /**
     * Build quota + usage report for day/week/month filter.
     *
     * @return array<string, mixed>
     */
    public function report(string $period = 'day'): array
    {
        $period = array_key_exists($period, self::PERIOD_DAYS) ? $period : 'day';
        $days = self::PERIOD_DAYS[$period];
        $apiKey = $this->apiKey();
        $base = (string) config('ol_bot.vibe_api_base');

        if ($apiKey === '' || $base === '') {
            return $this->emptyReport($period, 'missing_api_key');
        }

        try {
            $usageResponse = Http::withHeaders([
                'X-Api-Key' => $apiKey,
                'Accept' => 'application/json',
            ])->timeout(20)->get($base.'/ai/usage', ['days' => $days]);

            $quotaResponse = Http::withHeaders([
                'X-Api-Key' => $apiKey,
                'Accept' => 'application/json',
            ])->timeout(20)->get($base.'/ai/quota');

            if (! $usageResponse->successful() || ! $quotaResponse->successful()) {
                return $this->emptyReport($period, 'api_http_error');
            }

            $usageBody = $usageResponse->json();
            $quotaBody = $quotaResponse->json();
            $usage = is_array($usageBody['data'] ?? null) ? $usageBody['data'] : [];
            $quota = is_array($quotaBody['data'] ?? null) ? $quotaBody['data'] : [];
            $totals = is_array($usage['totals'] ?? null) ? $usage['totals'] : [];
            $byModel = is_array($usage['byModel'] ?? null) ? $usage['byModel'] : [];

            $promptTokens = (int) ($totals['promptTokens'] ?? 0);
            $completionTokens = (int) ($totals['completionTokens'] ?? 0);
            $totalTokens = (int) ($totals['totalTokens'] ?? ($promptTokens + $completionTokens));
            $calls = (int) ($totals['calls'] ?? 0);
            $cost = (float) ($totals['cost'] ?? 0);
            $paidEstimate = $this->estimatePaidUsd($promptTokens, $completionTokens);
            $modelId = (string) (config('ol_bot.ai_model') ?: 'bitrix/bitrixgpt-5.5-thinking');
            $isFree = $this->isFreeModel($modelId) || $cost <= 0.0;

            $pctUsed = (float) ($quota['pctUsed'] ?? 0);
            $pacing = is_array($quota['pacing'] ?? null) ? $quota['pacing'] : [];
            $dayPct = (float) data_get($pacing, 'day.pctUsed', 0);
            $weekPct = (float) data_get($pacing, 'week.pctUsed', 0);

            $models = [];
            foreach ($byModel as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = (string) ($row['modelId'] ?? '');
                $p = (int) ($row['promptTokens'] ?? 0);
                $c = (int) ($row['completionTokens'] ?? 0);
                $models[] = [
                    'modelId' => $id,
                    'calls' => (int) ($row['calls'] ?? 0),
                    'promptTokens' => $p,
                    'completionTokens' => $c,
                    'totalTokens' => (int) ($row['totalTokens'] ?? ($p + $c)),
                    'cost' => (float) ($row['cost'] ?? 0),
                    'paidEstimateUsd' => round($this->estimatePaidUsd($p, $c), 2),
                    'free' => $this->isFreeModel($id) || (float) ($row['cost'] ?? 0) <= 0.0,
                    'pctOfLimit' => $this->modelPctOfLimit($quota, $id),
                ];
            }

            return [
                'ok' => true,
                'error' => null,
                'period' => $period,
                'days' => $days,
                'model' => $modelId,
                'usage' => [
                    'calls' => $calls,
                    'promptTokens' => $promptTokens,
                    'completionTokens' => $completionTokens,
                    'totalTokens' => $totalTokens,
                    'credits' => round($totalTokens / 1000, 3),
                    'costUsd' => round($cost, 4),
                    'paidEstimateUsd' => round($paidEstimate, 2),
                    'free' => $isFree,
                    'since' => data_get($usage, 'period.since'),
                ],
                'quota' => [
                    'pctUsed' => $pctUsed,
                    'pctLeft' => max(0, round(100 - $pctUsed, 1)),
                    'exhausted' => (bool) ($quota['exhausted'] ?? false),
                    'resetAt' => $quota['resetAt'] ?? null,
                    'scheme' => $quota['scheme'] ?? null,
                    'periodStart' => data_get($quota, 'period.start'),
                    'dayPctUsed' => $dayPct,
                    'dayPctLeft' => max(0, round(100 - $dayPct, 1)),
                    'dayResetAt' => data_get($pacing, 'day.resetAt'),
                    'weekPctUsed' => $weekPct,
                    'weekPctLeft' => max(0, round(100 - $weekPct, 1)),
                    'weekResetAt' => data_get($pacing, 'week.resetAt'),
                    'pacingMode' => data_get($pacing, 'mode'),
                ],
                'byModel' => $models,
                'fetchedAt' => now()->toIso8601String(),
            ];
        } catch (Throwable $e) {
            Log::warning('ol_bot.alaio_quota_failed', ['error' => $e->getMessage()]);

            return $this->emptyReport($period, 'api_exception');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyReport(string $period, string $error): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'period' => $period,
            'days' => self::PERIOD_DAYS[$period] ?? 1,
            'model' => (string) config('ol_bot.ai_model'),
            'usage' => null,
            'quota' => null,
            'byModel' => [],
            'fetchedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $quota
     */
    private function modelPctOfLimit(array $quota, string $modelId): ?float
    {
        $rows = is_array($quota['byModel'] ?? null) ? $quota['byModel'] : [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((string) ($row['modelId'] ?? '') === $modelId) {
                return isset($row['pctOfLimit']) ? (float) $row['pctOfLimit'] : null;
            }
        }

        return null;
    }

    /**
     * Approximate paid USD if traffic were billed as bitrixgpt-5.5-agent (1 Ꝟ = $1).
     */
    private function estimatePaidUsd(int $promptTokens, int $completionTokens): float
    {
        return ($promptTokens / 1_000_000) * self::AGENT_PROMPT_PER_M
            + ($completionTokens / 1_000_000) * self::AGENT_COMPLETION_PER_M;
    }

    private function isFreeModel(string $modelId): bool
    {
        $id = str_starts_with($modelId, 'bitrix/') ? $modelId : 'bitrix/'.$modelId;

        return in_array($id, self::FREE_MODELS, true);
    }

    private function apiKey(): string
    {
        $configured = $this->normalizeApiKey((string) config('ol_bot.vibe_api_key'));
        if ($configured !== '') {
            return $configured;
        }

        $path = (string) config('ol_bot.vibe_env_path');
        if ($path === '' || ! is_readable($path)) {
            return '';
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_starts_with($line, 'VIBE_API_KEY=')) {
                continue;
            }

            return $this->normalizeApiKey(substr($line, strlen('VIBE_API_KEY=')));
        }

        return '';
    }

    /**
     * @param  string  $value
     */
    private function normalizeApiKey(string $value): string
    {
        $key = trim($value, " \t\"'\r\n");
        while (str_starts_with($key, '=')) {
            $key = substr($key, 1);
        }

        return trim($key);
    }
}

