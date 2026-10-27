<?php

namespace App\Services;

class WazzupAlert
{
    public function __construct(
        private readonly DailyAlert $dailyAlert,
    ) {}

    /**
     * Notify Bitrix when a Wazzup channel needs payment attention (once per channel per day).
     *
     * @param  array{channel_id: string, transport: string, state: string, plain_id: string, name: string}  $channel
     */
    public function notifyIfUnpaid(array $channel): void
    {
        $state = strtolower(trim((string) ($channel['state'] ?? '')));
        $alertStates = config('services.wazzup.alert_states', ['notenoughmoney', 'disabled']);
        if (! is_array($alertStates)) {
            $alertStates = ['notenoughmoney', 'disabled'];
        }
        $alertStates = array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            $alertStates
        );

        if ($state === '' || ! in_array($state, $alertStates, true)) {
            return;
        }

        $channelId = trim((string) ($channel['channel_id'] ?? ''));
        if ($channelId === '') {
            return;
        }

        $portal = strtoupper((string) config('services.wazzup.portal', 'HK'));
        $label = $this->channelLabel($channel);

        $this->dailyAlert->notifyOnce(
            'wazzup:channel_alert:'.$channelId.':'.$this->dailyAlert->todayKey(),
            'wazzup:'.strtolower($portal).':'.$channelId,
            [
                'portal' => $portal,
                'service' => 'Wazzup — '.$label,
                'url' => (string) config('services.wazzup.cabinet_url', ''),
                'line' => $label,
                'pay_method' => (string) config('services.wazzup.pay_method', 'Карта'),
                'date' => $this->dailyAlert->todayNoticeDate(),
                'balance' => '[B]'.$state.'[/B]',
            ],
            'Wazzup channel Bitrix alert failed',
            ['channel_id' => $channelId, 'state' => $state],
        );
    }

    /**
     * @param  array{channel_id: string, transport: string, state: string, plain_id: string, name: string}  $channel
     */
    private function channelLabel(array $channel): string
    {
        $name = trim((string) ($channel['name'] ?? ''));
        $plainId = trim((string) ($channel['plain_id'] ?? ''));
        $transport = trim((string) ($channel['transport'] ?? ''));

        if ($name !== '' && $plainId !== '' && ! str_contains($name, $plainId)) {
            $label = $name.' / '.$plainId;
        } elseif ($name !== '') {
            $label = $name;
        } elseif ($plainId !== '') {
            $label = $plainId;
        } else {
            $label = (string) ($channel['channel_id'] ?? 'channel');
        }

        return $transport !== '' ? $label.' ('.$transport.')' : $label;
    }
}
