<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ServiceAlertTask
{
    private const OPEN_STATUSES = ['1', '2', '3'];

    public function __construct(
        private readonly BitrixWebhook $webhookClient
    ) {}

    /**
     * Ensure one open payment task exists for an external-API alert notice.
     *
     * @param  array{portal: string, service: string, url?: string, line?: string|null, line_url?: string, pay_method?: string, date: string, balance?: string}  $payload
     * @return int Open task id, or 0 when not created/found
     */
    public function ensureOpen(array $payload, string $dedupeKey): int
    {
        if (! (bool) config('services.service_alert_tasks.enabled', true)) {
            return 0;
        }

        $webhook = $this->webhook();
        $groupId = $this->groupId($webhook);
        if ($webhook === '' || $groupId <= 0) {
            Log::warning('SERVICE_ALERT_TASK_SKIP_CONFIG', [
                'has_webhook' => $webhook !== '',
                'group_id' => $groupId,
                'dedupe_key' => $dedupeKey,
            ]);

            return 0;
        }

        $title = $this->title($payload);
        $cacheKey = 'service_alert_task:'.$dedupeKey;

        try {
            $cachedTaskId = (int) Cache::get($cacheKey, 0);
            if ($cachedTaskId > 0 && $this->isOpenTask($webhook, $cachedTaskId)) {
                Log::info('SERVICE_ALERT_TASK_SKIP_OPEN', [
                    'task_id' => $cachedTaskId,
                    'dedupe_key' => $dedupeKey,
                    'source' => 'cache',
                ]);

                return $cachedTaskId;
            }

            $existingId = $this->findOpenTaskId($webhook, $title);
            if ($existingId > 0) {
                Cache::forever($cacheKey, $existingId);
                Log::info('SERVICE_ALERT_TASK_SKIP_OPEN', [
                    'task_id' => $existingId,
                    'dedupe_key' => $dedupeKey,
                    'source' => 'list',
                ]);

                return $existingId;
            }

            $taskId = $this->createTask($webhook, $groupId, $title, $payload);
            if ($taskId > 0) {
                Cache::forever($cacheKey, $taskId);
                Log::info('SERVICE_ALERT_TASK_CREATED', [
                    'task_id' => $taskId,
                    'title' => $title,
                    'dedupe_key' => $dedupeKey,
                ]);
            }

            return $taskId;
        } catch (Throwable $e) {
            Log::error('SERVICE_ALERT_TASK_FAILED', [
                'dedupe_key' => $dedupeKey,
                'title' => $title,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Absolute Bitrix task URL for the AE portal.
     */
    public function taskUrl(int $taskId): string
    {
        if ($taskId <= 0) {
            return '';
        }

        $domain = rtrim((string) config('services.bitrix.portal_domain', 'colifeae.bitrix24.eu'), '/');
        $domain = preg_replace('#^https?://#i', '', $domain) ?: 'colifeae.bitrix24.eu';

        return 'https://'.$domain.'/company/personal/user/0/tasks/task/view/'.$taskId.'/';
    }

    /**
     * @param  array{portal: string, service: string, url?: string, line?: string|null, pay_method?: string, date: string, balance?: string}  $payload
     */
    private function title(array $payload): string
    {
        $service = trim((string) ($payload['service'] ?? 'Сервис'));
        $portal = $this->portalDomain((string) ($payload['portal'] ?? ''));

        return 'Оплата сервиса '.$service.' портал '.$portal;
    }

    private function portalDomain(string $portal): string
    {
        $code = strtoupper(trim($portal));

        return match ($code) {
            'AE' => (string) config('services.bitrix.portal_domain', 'colifeae.bitrix24.eu'),
            'HK' => (string) config('services.b24_hk.portal_domain', 'colifepacific.bitrix24.com'),
            'TR' => (string) config('services.b24_tr.portal_domain', 'colife.bitrix24.com.tr'),
            default => $portal !== '' ? $portal : (string) config('services.bitrix.portal_domain', 'colifeae.bitrix24.eu'),
        };
    }

    /**
     * @param  array{portal: string, service: string, url?: string, line?: string|null, pay_method?: string, date: string, balance?: string}  $payload
     */
    private function description(array $payload): string
    {
        $lines = [
            'Автозадача по напоминанию из внешнего API.',
            'Сервис: '.trim((string) ($payload['service'] ?? '')),
            'Портал: '.$this->portalDomain((string) ($payload['portal'] ?? '')),
            'Дата платежа: '.trim((string) ($payload['date'] ?? '')),
        ];

        $line = trim((string) ($payload['line'] ?? ''));
        if ($line !== '') {
            $lines[] = 'Линия: '.$line;
        }

        $payMethod = trim((string) ($payload['pay_method'] ?? ''));
        if ($payMethod !== '') {
            $lines[] = 'Способ оплаты: '.$payMethod;
        }

        $balance = trim(strip_tags(str_replace(['[B]', '[/B]'], '', (string) ($payload['balance'] ?? ''))));
        if ($balance !== '') {
            $lines[] = 'Баланс: '.$balance;
        }

        $url = trim((string) ($payload['url'] ?? ''));
        if ($url !== '') {
            $lines[] = 'Кабинет: '.$url;
        }

        return implode("\n", $lines);
    }

    private function webhook(): string
    {
        $dedicated = trim((string) config('services.service_alert_tasks.webhook', ''));
        if ($dedicated !== '') {
            return $dedicated;
        }

        return trim((string) config('services.bitrix.webhook', ''));
    }

    private function groupId(string $webhook): int
    {
        $configured = (int) config('services.service_alert_tasks.group_id', 0);
        if ($configured > 0) {
            return $configured;
        }

        $sampleTaskId = (int) config('services.service_alert_tasks.sample_task_id', 514152);
        if ($sampleTaskId <= 0 || $webhook === '') {
            return 0;
        }

        $cacheKey = 'service_alert_tasks:group_id:'.$sampleTaskId;
        $cached = Cache::get($cacheKey);
        if (is_numeric($cached) && (int) $cached > 0) {
            return (int) $cached;
        }

        $json = $this->webhookClient->callRaw('tasks.task.get', [
            'taskId' => $sampleTaskId,
        ], $webhook);

        $groupId = (int) data_get($json, 'result.task.groupId', 0);
        if ($groupId > 0) {
            Cache::put($cacheKey, $groupId, now()->addDay());
        }

        return $groupId;
    }

    private function isOpenTask(string $webhook, int $taskId): bool
    {
        $json = $this->webhookClient->callRaw('tasks.task.get', [
            'taskId' => $taskId,
            'select' => ['ID', 'STATUS', 'REAL_STATUS'],
        ], $webhook);

        if ((string) ($json['error'] ?? '') !== '') {
            return false;
        }

        $status = (string) data_get($json, 'result.task.status', data_get($json, 'result.task.realStatus', ''));

        return in_array($status, self::OPEN_STATUSES, true);
    }

    private function findOpenTaskId(string $webhook, string $title): int
    {
        $json = $this->webhookClient->callRaw('tasks.task.list', [
            'filter' => [
                'TITLE' => $title,
                'RESPONSIBLE_ID' => (int) config('services.service_alert_tasks.responsible_id', 186098),
                'REAL_STATUS' => self::OPEN_STATUSES,
            ],
            'select' => ['ID', 'TITLE', 'STATUS', 'REAL_STATUS'],
            'order' => ['ID' => 'DESC'],
        ], $webhook);

        $tasks = data_get($json, 'result.tasks', []);
        if (! is_array($tasks)) {
            return 0;
        }

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            if (trim((string) ($task['title'] ?? '')) !== $title) {
                continue;
            }
            $id = (int) ($task['id'] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * @param  array{portal: string, service: string, url?: string, line?: string|null, pay_method?: string, date: string, balance?: string}  $payload
     */
    private function createTask(string $webhook, int $groupId, string $title, array $payload): int
    {
        $fields = [
            'TITLE' => $title,
            'DESCRIPTION' => $this->description($payload),
            'RESPONSIBLE_ID' => (int) config('services.service_alert_tasks.responsible_id', 186098),
            'CREATED_BY' => (int) config('services.service_alert_tasks.created_by', 4),
            'GROUP_ID' => $groupId,
            'AUDITORS' => array_values(array_filter(array_map(
                'intval',
                (array) config('services.service_alert_tasks.auditor_ids', [90152, 10])
            ))),
            'DEADLINE' => Carbon::today('Europe/Moscow')->endOfDay()->format('Y-m-d H:i:s'),
        ];

        $json = $this->webhookClient->call('tasks.task.add', [
            'fields' => $fields,
        ], $webhook);

        return (int) data_get($json, 'result.task.id', data_get($json, 'result.taskId', 0));
    }
}
