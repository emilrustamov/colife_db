<?php

namespace App\Jobs;

use App\Models\BitrixToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RegisterActivityJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public string $domain) {}

    /**
     * Register custom wait activity in Bitrix bizproc.
     */
    public function handle(): void
    {
        $accessToken = (string) BitrixToken::query()
            ->where('domain', $this->domain)
            ->value('access_token');
        if ($accessToken === '') {
            Log::warning('BP REGISTER FAILED', [
                'domain' => $this->domain,
                'status' => 0,
                'body' => ['error' => 'NO_ACCESS_TOKEN'],
            ]);

            return;
        }

        $activityFields = [
            'DOCUMENT_TYPE' => ['crm', 'CCrmDocumentDeal', 'DEAL'],
            'HANDLER' => 'https://db.colifeb24apps.ru/api/bp/wait',
            'AUTH_USER_ID' => 4,
            'USE_SUBSCRIPTION' => 'Y',
            'NAME' => '[Эмиль Рустамов] Пауза',
            'DESCRIPTION' => 'Пауза минимум 10 секунд. До даты — до 00:00:00 выбранного дня по времени сервера.',
            'PROPERTIES' => [
                'mode' => [
                    'Name' => 'Тип',
                    'Type' => 'select',
                    'Required' => 'Y',
                    'Default' => 'seconds',
                    'Options' => [
                        'date' => 'До даты',
                        'seconds' => 'Секунды',
                    ],
                ],
                'date' => [
                    'Name' => 'Дата',
                    'Type' => 'date',
                ],
                'seconds' => [
                    'Name' => 'Секунды',
                    'Type' => 'int',
                    'Default' => 10,
                ],
            ],
        ];

        $response = Http::withToken($accessToken)
            ->post('https://'.$this->domain.'/rest/bizproc.activity.add', [
                'CODE' => 'custom_wait_v2',
            ] + $activityFields);

        $body = $response->json();
        if (
            is_array($body)
            && (string) ($body['error'] ?? '') === 'ERROR_ACTIVITY_ALREADY_INSTALLED'
        ) {
            $response = Http::withToken($accessToken)
                ->post('https://'.$this->domain.'/rest/bizproc.activity.update', [
                    'CODE' => 'custom_wait_v2',
                    'FIELDS' => $activityFields,
                ]);
            $body = $response->json();
        }

        Log::info('BP REGISTER RESULT', [
            'domain' => $this->domain,
            'status' => $response->status(),
            'body' => $body,
        ]);

        if ($response->failed()) {
            Log::warning('BP REGISTER FAILED', [
                'domain' => $this->domain,
                'status' => $response->status(),
                'body' => $body,
            ]);
        }
    }
}
