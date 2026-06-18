<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BitrixPauseDateResolver
{
    private const int MIN_DELAY_SECONDS = 10;

    public function resolveDelaySeconds(mixed $dateValue): int
    {
        $raw = $this->extractDateRaw($dateValue);
        if ($raw === null) {
            $this->logFallback('invalid_date_value', ['value' => $dateValue]);

            return self::MIN_DELAY_SECONDS;
        }

        if ($raw === '' || str_contains($raw, '{=')) {
            $this->logFallback($raw === '' ? 'empty' : 'unresolved_expression', ['raw' => $raw]);

            return self::MIN_DELAY_SECONDS;
        }

        $timezone = $this->portalTimezone();
        $parsed = $this->parseDateAtMidnight($raw, $timezone);

        if ($parsed === null) {
            $this->logFallback('parse_failed', ['raw' => $raw, 'timezone' => $timezone]);

            return self::MIN_DELAY_SECONDS;
        }

        $delay = max($parsed->getTimestamp() - now()->getTimestamp(), self::MIN_DELAY_SECONDS);

        Log::info('PAUSE_DATE_PARSED', [
            'raw' => $raw,
            'date_only' => $this->extractCalendarDate($raw),
            'timezone' => $timezone,
            'target_at_portal' => $parsed->copy()->timezone($timezone)->toIso8601String(),
            'delay_seconds' => $delay,
        ]);

        return $delay;
    }

    public function resolveSecondsDelay(mixed $value): int
    {
        if (! is_scalar($value)) {
            return self::MIN_DELAY_SECONDS;
        }

        $raw = trim((string) $value);
        if ($raw === '' || str_contains($raw, '{=')) {
            return self::MIN_DELAY_SECONDS;
        }

        return max((int) $raw, self::MIN_DELAY_SECONDS);
    }

    public function portalTimezone(): string
    {
        return (string) config('services.bitrix.portal_timezone', 'Europe/Moscow');
    }

    private function parseDateAtMidnight(string $raw, string $timezone): ?Carbon
    {
        $calendarDate = $this->extractCalendarDate($raw);
        if ($calendarDate === null) {
            return null;
        }

        if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $calendarDate)) {
            try {
                return Carbon::createFromFormat('d.m.Y', $calendarDate, $timezone)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $calendarDate)) {
            try {
                return Carbon::createFromFormat('Y-m-d', $calendarDate, $timezone)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function extractCalendarDate(string $raw): ?string
    {
        $raw = trim($raw);

        if (preg_match('/^(\d{2}\.\d{2}\.\d{4})/', $raw, $matches)) {
            return $matches[1];
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractDateRaw(mixed $dateValue): ?string
    {
        if (! is_scalar($dateValue) && ! is_array($dateValue)) {
            return null;
        }

        if (is_array($dateValue)) {
            foreach (['value', 'VALUE', 'date', 'DATE', 'text', 'TEXT'] as $key) {
                if (isset($dateValue[$key]) && is_scalar($dateValue[$key])) {
                    return trim((string) $dateValue[$key]);
                }
            }

            return null;
        }

        return trim((string) $dateValue);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logFallback(string $reason, array $context): void
    {
        Log::warning('PAUSE_DATE_FALLBACK', ['reason' => $reason] + $context);
    }
}
