<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use RuntimeException;

class ServicePayApi
{
    public function __construct(
        private readonly BitrixWebhook $webhookClient
    ) {}

    /**
     * Fetch payment items from Bitrix SPA «Оплата сервисов».
     *
     * @return list<array{id: int, title: string, stage_id: string, service_id: int, service: string, portal: string, pay_method: string|null, pay_date: string|null, notify_at: string|null, amount: string|null}>
     */
    public function getItems(): array
    {
        $entityTypeId = (int) config('services.service_payments.entity_type_id', 1094);
        $categoryId = (int) config('services.service_payments.category_id', 74);
        $serviceNames = config('services.service_payments.services', []);
        if (! is_array($serviceNames) || $serviceNames === []) {
            throw new RuntimeException('Service payments mapping is not configured.');
        }

        $serviceIds = array_values(array_diff(
            array_map('intval', array_keys($serviceNames)),
            $this->apiOwnedServiceIds()
        ));
        if ($serviceIds === []) {
            throw new RuntimeException('Service payments mapping is not configured.');
        }
        $items = [];
        $start = 0;

        while (true) {
            $response = $this->webhookClient->call('crm.item.list', [
                'entityTypeId' => $entityTypeId,
                'filter' => ['categoryId' => $categoryId],
                'select' => [
                    'id',
                    'title',
                    'stageId',
                    'ufCrm58Date',
                    'ufCrm58Services',
                    'ufCrm58Notificication',
                    'ufCrm58Portal',
                    'ufCrm58_1787301535197',
                    'ufCrm58_1787305447677',
                ],
                'start' => $start,
            ]);

            $page = data_get($response, 'result.items', []);
            if (! is_array($page) || $page === []) {
                break;
            }

            foreach ($page as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $serviceId = (int) ($item['ufCrm58Services'] ?? 0);
                if (! in_array($serviceId, $serviceIds, true)) {
                    continue;
                }

                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }

                $items[] = [
                    'id' => $id,
                    'title' => (string) ($item['title'] ?? ''),
                    'stage_id' => (string) ($item['stageId'] ?? ''),
                    'service_id' => $serviceId,
                    'service' => $this->serviceName($serviceId, (string) ($item['title'] ?? ''), $serviceNames),
                    'portal' => $this->portal((int) ($item['ufCrm58Portal'] ?? 0)),
                    'pay_method' => $this->payMethod((int) ($item['ufCrm58_1787301535197'] ?? 0), $serviceId),
                    'pay_date' => $this->date($item['ufCrm58Date'] ?? null),
                    'notify_at' => $this->date($item['ufCrm58Notificication'] ?? null),
                    'amount' => $this->amount($item['ufCrm58_1787305447677'] ?? null),
                ];
            }

            $next = data_get($response, 'next');
            if (! is_numeric($next)) {
                break;
            }

            $start = (int) $next;
        }

        return $items;
    }

    /**
     * Service IDs that are collected from vendor APIs and must not be alerted from SPA.
     *
     * @return list<int>
     */
    private function apiOwnedServiceIds(): array
    {
        $owned = config('services.service_payments.api_owned_services', []);
        if (! is_array($owned)) {
            return [];
        }

        return array_map('intval', array_keys($owned));
    }

    /**
     * @param  array<int|string, string>  $serviceNames
     */
    private function serviceName(int $serviceId, string $title, array $serviceNames): string
    {
        $base = (string) ($serviceNames[$serviceId] ?? $title);
        if ($serviceId !== 11366) {
            return $base;
        }

        $stripped = trim((string) preg_replace('/^Оплата домена\s+/u', '', $title));
        $stripped = trim((string) preg_replace('/^Оплата\s+/u', '', $stripped));

        return $stripped !== '' ? $stripped : $base;
    }

    /**
     * Map Bitrix portal enum to AE/HK.
     */
    private function portal(int $enumId): string
    {
        $portals = config('services.service_payments.portals', []);

        return is_array($portals) ? (string) ($portals[$enumId] ?? 'AE') : 'AE';
    }

    /**
     * Map Bitrix payment type enum to Счёт/Карта.
     */
    private function payMethod(int $enumId, int $serviceId): ?string
    {
        $methods = config('services.service_payments.pay_methods', []);
        if (is_array($methods) && $enumId > 0) {
            $value = $methods[$enumId] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $defaults = config('services.service_payments.default_pay_methods', []);
        $fallback = is_array($defaults) ? ($defaults[$serviceId] ?? null) : null;

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    /**
     * Take the calendar date from a Bitrix datetime, ignoring time and timezone.
     */
    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($value), $matches) === 1) {
            return $matches[1];
        }

        return Carbon::parse($value)->format('Y-m-d');
    }

    /**
     * Format Bitrix money (`1899|RUB`) for the notice.
     */
    private function amount(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        [$sum, $currency] = array_pad(explode('|', $value, 2), 2, '');
        if (! is_numeric($sum)) {
            return $value;
        }

        $formatted = number_format((float) $sum, 0, '.', '');
        $currency = strtoupper(trim($currency));

        return match ($currency) {
            'RUB', 'RUR' => '₽'.$formatted,
            'USD' => '$'.$formatted,
            default => $formatted.($currency !== '' ? ' '.$currency : ''),
        };
    }
}
