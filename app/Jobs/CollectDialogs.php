<?php

namespace App\Jobs;

use App\Models\DialogBalance;
use App\Services\ChatAppApi;
use App\Services\ChatAppAlert;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CollectDialogs
{
    use Dispatchable;

    /**
     * Collect dialog balances from ChatApp accounts and notify Bitrix when remaining is low.
     */
    public function handle(
        ChatAppApi $chatAppApiService,
        ChatAppAlert $alertService
    ): void {
        $collectedAt = Carbon::today()->toDateString();
        $accounts = config('services.chatapp.accounts', []);

        if (! is_array($accounts)) {
            return;
        }

        $failures = [];

        foreach ($accounts as $account => $accountConfig) {
            if (! is_array($accountConfig) || ! $this->accountReady($accountConfig)) {
                continue;
            }

            try {
                $this->collectAccount($chatAppApiService, $alertService, (string) $account, $collectedAt);
            } catch (\Throwable $e) {
                $failures[] = (string) $account;
                Log::error('ChatApp collect failed', [
                    'account' => $account,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($failures !== []) {
            throw new \RuntimeException('ChatApp collect failed for accounts: '.implode(', ', $failures));
        }
    }

    /**
     * @param  array<string, mixed>  $accountConfig
     */
    private function accountReady(array $accountConfig): bool
    {
        return trim((string) ($accountConfig['email'] ?? '')) !== ''
            && trim((string) ($accountConfig['password'] ?? '')) !== ''
            && trim((string) ($accountConfig['app_id'] ?? '')) !== '';
    }

    /**
     * Collect balances for a single ChatApp account.
     */
    private function collectAccount(
        ChatAppApi $chatAppApiService,
        ChatAppAlert $alertService,
        string $account,
        string $collectedAt
    ): void {
        $licenses = $chatAppApiService->getLicenses($account);

        foreach ($licenses as $license) {
            if (! is_array($license)) {
                continue;
            }

            if (! ($license['active'] ?? false)) {
                continue;
            }

            $licenseId = (string) ($license['licenseId'] ?? '');
            $licenseName = (string) ($license['licenseName'] ?? '');
            $messengers = $license['messenger'] ?? [];

            if ($licenseId === '' || ! is_array($messengers)) {
                continue;
            }

            foreach ($messengers as $messenger) {
                if (! is_array($messenger)) {
                    continue;
                }

                $messengerType = (string) ($messenger['type'] ?? 'unknown');
                $balance = $this->extractBalance($messenger)
                    ?? $chatAppApiService->getBalanceConversations($account, $licenseId, $messengerType);
                if ($balance === null) {
                    continue;
                }

                $messengerName = (string) ($messenger['name'] ?? $messengerType);
                $info = is_array($messenger['info'] ?? null) ? $messenger['info'] : [];
                $phone = isset($info['phone']) ? (string) $info['phone'] : null;

                $record = DialogBalance::query()->updateOrCreate(
                    [
                        'line_id' => $account.':'.$licenseId.':'.$messengerType,
                        'collected_at' => $collectedAt,
                    ],
                    [
                        'line_name' => $licenseName !== '' ? $licenseName.' / '.$messengerName : $messengerName,
                        'phone_number' => $phone !== '' ? $phone : null,
                        'total_limit' => $balance['total_limit'],
                        'used' => $balance['used'],
                        'remaining' => $balance['remaining'],
                    ]
                );

                $alertService->notifyIfLow($record, $account);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $messenger
     * @return array{total_limit: int, used: int, remaining: int}|null
     */
    private function extractBalance(array $messenger): ?array
    {
        $plan = $messenger['subscriptionPlan'] ?? null;
        if (! is_array($plan)) {
            return null;
        }

        $restrictions = is_array($plan['restrictions'] ?? null) ? $plan['restrictions'] : [];
        $spends = is_array($plan['spends'] ?? null) ? $plan['spends'] : [];
        $balances = is_array($plan['balances'] ?? null) ? $plan['balances'] : [];

        if (! array_key_exists('chatsMonthLimit', $balances)) {
            return null;
        }

        return [
            'total_limit' => (int) ($restrictions['chatsMonthLimit'] ?? 0),
            'used' => (int) ($spends['chatsMonthLimit'] ?? 0),
            'remaining' => (int) $balances['chatsMonthLimit'],
        ];
    }
}
