<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PauseDates
{
    private const int MIN_DELAY_SECONDS = 10;

    /**
     * Delay until the given Bitrix date/datetime in the pause datetime timezone.
     */
    public function resolveDelaySeconds(mixed $dateValue, ?string $domain = null): int
    {
        $raw = $this->extractDateRaw($dateValue);
        if ($raw === null) {
            $this->logFallback('invalid_date_value', ['value' => $dateValue, 'domain' => $domain]);

            return self::MIN_DELAY_SECONDS;
        }

        if ($raw === '' || str_contains($raw, '{=')) {
            $this->logFallback($raw === '' ? 'empty' : 'unresolved_expression', [
                'raw' => $raw,
                'domain' => $domain,
            ]);

            return self::MIN_DELAY_SECONDS;
        }

        $timezone = $this->portalTimezone($domain);
        $parsed = $this->parseTarget($raw, $timezone);

        if ($parsed === null) {
            $this->logFallback('parse_failed', [
                'raw' => $raw,
                'timezone' => $timezone,
                'domain' => $domain,
            ]);

            return self::MIN_DELAY_SECONDS;
        }

        $delay = max($parsed->getTimestamp() - now()->getTimestamp(), self::MIN_DELAY_SECONDS);

        Log::channel('bitrix_pauses')->info('PAUSE_DATE_PARSED', [
            'raw' => $raw,
            'domain' => $domain,
            'timezone' => $timezone,
            'target_at_portal' => $parsed->copy()->timezone($timezone)->toIso8601String(),
            'target_at_server' => $parsed->copy()->timezone('UTC')->toIso8601String(),
            'delay_seconds' => $delay,
        ]);

        return $delay;
    }

    /**
     * Delay by a positive seconds value.
     */
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

    /**
     * Timezone of datetime strings Bitrix sends for pause activities (editor user TZ).
     */
    public function portalTimezone(?string $domain = null): string
    {
        $domain = strtolower(trim((string) $domain));
        $hkDomain = strtolower(trim((string) config('services.b24_hk.portal_domain', '')));

        if ($domain !== '' && $hkDomain !== '' && $domain === $hkDomain) {
            return (string) config('services.b24_hk.pause_datetime_timezone', 'Asia/Tashkent');
        }

        return (string) config('services.bitrix.pause_datetime_timezone', 'Asia/Tashkent');
    }

    /**
     * Parse Bitrix date/datetime into the wait target.
     * With time — wait until that moment. Date-only (or 00:00) — until 00:00 of that day;
     * if that midnight is already past — until next midnight.
     */
    private function parseTarget(string $raw, string $timezone): ?Carbon
    {
        $raw = trim($raw);

        if (preg_match('/^(\d{2}\.\d{2}\.\d{4})(?:\s+(\d{2}:\d{2}(?::\d{2})?))?/', $raw, $matches) === 1) {
            return $this->buildTarget($matches[1], $matches[2] ?? null, 'd.m.Y', $timezone);
        }

        if (preg_match('/^(\d{2}\/\d{2}\/\d{4})(?:\s+(\d{2}:\d{2}(?::\d{2})?))?/', $raw, $matches) === 1) {
            return $this->buildTarget($matches[1], $matches[2] ?? null, 'd/m/Y', $timezone);
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}(?::\d{2})?))?/', $raw, $matches) === 1) {
            return $this->buildTarget($matches[1], $matches[2] ?? null, 'Y-m-d', $timezone);
        }

        return null;
    }

    /**
     * @param  non-empty-string  $datePart
     * @param  non-empty-string  $dateFormat
     */
    private function buildTarget(string $datePart, ?string $timePart, string $dateFormat, string $timezone): ?Carbon
    {
        $timePart = $timePart !== null ? trim($timePart) : '';
        $hasMeaningfulTime = $timePart !== '' && ! preg_match('/^00:00(?::00)?$/', $timePart);

        try {
            if ($hasMeaningfulTime) {
                $timeFormat = strlen($timePart) === 5 ? 'H:i' : 'H:i:s';

                return Carbon::createFromFormat(
                    $dateFormat.' '.$timeFormat,
                    $datePart.' '.$timePart,
                    $timezone
                );
            }

            $parsed = Carbon::createFromFormat($dateFormat, $datePart, $timezone)->startOfDay();
            if ($parsed->lessThanOrEqualTo(now())) {
                return $parsed->addDay();
            }

            return $parsed;
        } catch (\Throwable) {
            return null;
        }
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

            if ($dateValue !== [] && is_scalar(reset($dateValue))) {
                return trim((string) reset($dateValue));
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
        Log::channel('bitrix_pauses')->warning('PAUSE_DATE_FALLBACK', ['reason' => $reason] + $context);
    }
}
