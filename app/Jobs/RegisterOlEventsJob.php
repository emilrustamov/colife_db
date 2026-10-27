<?php

namespace App\Jobs;

use App\Models\BitrixToken;
use App\Services\BitrixOAuth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
    public function handle(BitrixOAuth $oauth): void
    {
        $logger = Log::channel('bitrix_open_lines');
        $domain = $oauth->normalizeDomain($this->domain);
        $expectedDomain = $oauth->normalizeDomain((string) config('open_lines_source.portal_domain'));

        if ($domain === '' || strcasecmp($domain, $expectedDomain) !== 0) {
            $logger->warning('OL_EVENT_BIND_SKIP_DOMAIN', ['domain' => $domain]);

            return;
        }

        $accessToken = (string) BitrixToken::query()
            ->where('domain', $domain)
            ->value('access_token');

        if ($accessToken === '') {
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

            $response = Http::asForm()->post('https://'.$domain.'/rest/event.bind', [
                'event' => $eventName,
                'handler' => $handler,
                'auth' => $accessToken,
            ]);

            $body = $response->json();
            $error = is_array($body) ? (string) ($body['error'] ?? '') : '';
            $alreadyBound = str_contains(strtolower($error), 'already')
                || str_contains(strtolower((string) data_get($body, 'error_description', '')), 'already');

            $logger->info('OL_EVENT_BIND_RESULT', [
                'domain' => $domain,
                'event' => $eventName,
                'handler' => $handler,
                'status' => $response->status(),
                'body' => $body,
                'already_bound' => $alreadyBound,
            ]);

            if ($response->failed() && ! $alreadyBound) {
                $logger->warning('OL_EVENT_BIND_FAILED', [
                    'domain' => $domain,
                    'event' => $eventName,
                    'status' => $response->status(),
                    'body' => $body,
                    'hint' => $error === 'ERROR_EVENT_NOT_FOUND'
                        ? 'Add imopenlines (+ crm) scopes to the local app and reinstall'
                        : null,
                ]);
            }
        }
    }
}
