<?php

namespace App\Jobs;

use App\Services\ServicePayAlert;
use App\Services\ServicePayApi;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class CollectServicePay
{
    use Dispatchable;

    /**
     * Collect Bitrix service payment items and notify when the notification date has arrived.
     *
     * @return list<array{id: int, title: string, stage_id: string, service_id: int, service: string, portal: string, pay_method: string|null, pay_date: string|null, notify_at: string|null, amount: string|null}>
     */
    public function handle(
        ServicePayApi $servicePayApi,
        ServicePayAlert $alertService
    ): array {
        try {
            $items = $servicePayApi->getItems();

            foreach ($items as $item) {
                $alertService->notifyIfDue($item);
            }

            return $items;
        } catch (\Throwable $e) {
            Log::error('Service payments collect failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
