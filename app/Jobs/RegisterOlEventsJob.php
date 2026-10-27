<?php

namespace App\Jobs;

use App\Models\BitrixToken;
use App\Services\BitrixOAuth;
use App\Services\BitrixRest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RegisterOlEventsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public string $domain) {}

    /**
     * Bind Open Lines event handlers for the HK local app.
     */
    public function handle(BitrixOAuth $oauth, BitrixRest $bitrixRest): void
    {
        $logger = Log::channel('bitrix_open_lines');
        $domain = $oauth->normalizeDomain($this->domain);
        $portalDomain = $oauth->normalizeDomain((string) config('open_lines_source.portal_domain'));

        if ($domain === '' || strcasecmp($domain, $portalDomain) !== 0) {
            $logger->warning('OL_EVENT_BIND_SKIP_DOMAIN', ['domain' => $domain]);

            return;
        }

        if (BitrixToken::query()->where('domain', $domain)->doesntExist()) {
            $logger->warning('OL_EVENT_BIND_NO_TOKEN', ['domain' => $domain]);

            return;
        }

        $handler = trim((string) config('open_lines_source.handler_url'));
        if ($handler === '') {
            $logger->warning('OL_EVENT_BIND_NO_HANDLER', ['domain' => $domain]);

            return;
        }

        $events = config('open_lines_source.events', ['ONOPENLINEMESSAGEADD']);
        if (! is_array($events) || $events === []) {
            $events = ['ONOPENLINEMESSAGEADD'];
        }

        foreach ($events as $event) {
            $eventName = strtoupper(trim((string) $event));
            if ($eventName === '') {
                continue;
            }

            try {
                $body = $bitrixRest->postJson('event.bind', [
                    'event' => $eventName,
                    'handler' => $handler,
                ], $domain);
            } catch (Throwable $e) {
                $body = ['error' => $e->getMessage()];
            }

            $error = is_array($body) ? (string) ($body['error'] ?? '') : '';
            $alreadyBound = str_contains(strtolower($error), 'already')
                || str_contains(strtolower((string) data_get($body, 'error_description', '')), 'already');

            $logger->info('OL_EVENT_BIND_RESULT', [
                'domain' => $domain,
                'event' => $eventName,
                'handler' => $handler,
                'body' => $body,
                'already_bound' => $alreadyBound,
            ]);

            if ($error !== '' && ! $alreadyBound) {
                $logger->warning('OL_EVENT_BIND_FAILED', [
                    'domain' => $domain,
                    'event' => $eventName,
                    'body' => $body,
                    'hint' => str_contains(strtolower($error), 'error_event_not_found')
                        || str_contains(strtolower($error), 'insufficient_scope')
                        ? 'Reinstall HK app with crm + bizproc + imopenlines via /api/bp/install'
                        : null,
                ]);
            }
        }
    }
}
