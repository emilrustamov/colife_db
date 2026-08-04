<?php

namespace App\Jobs;

use App\Models\DialogBalance;
use App\Services\ChatAppApi;
use App\Services\ChatAppAlert;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Carbon;

class CollectDialogs
{
    use Dispatchable;

    /**
     * Collect dialog balances from ChatApp and notify Bitrix when remaining is low.
     */
    public function handle(
        ChatAppApi $chatAppApiService,
        ChatAppAlert $alertService
    ): void {
        $collectedAt = Carbon::today()->toDateString();
        $licenses = $chatAppApiService->getLicenses();

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
                    ?? $chatAppApiService->getBalanceConversations($licenseId, $messengerType);
                if ($balance === null) {
                    continue;
                }

                $messengerName = (string) ($messenger['name'] ?? $messengerType);
                $info = is_array($messenger['info'] ?? null) ? $messenger['info'] : [];
                $phone = isset($info['phone']) ? (string) $info['phone'] : null;

                $record = DialogBalance::query()->updateOrCreate(
                    [
                        'line_id' => $licenseId.':'.$messengerType,
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

                $alertService->notifyIfLow($record);
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
