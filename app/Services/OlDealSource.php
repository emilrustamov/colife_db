<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

class OlDealSource
{
    public function __construct(
        private readonly BitrixRest $bitrixRest,
        private readonly BitrixOAuth $oauth
    ) {}

    /**
     * Process one Open Lines message packet and set deal SOURCE_ID when matched.
     *
     * @param  array<string, mixed>  $packet
     */
    public function handle(string $domain, array $packet): void
    {
        $logger = Log::channel('bitrix_open_lines');
        $domain = $this->oauth->normalizeDomain($domain);
        $expectedDomain = $this->oauth->normalizeDomain((string) config('open_lines_source.portal_domain'));

        if ($domain === '' || $expectedDomain === '' || strcasecmp($domain, $expectedDomain) !== 0) {
            $logger->info('OL_SOURCE_SKIP_DOMAIN', ['domain' => $domain]);

            return;
        }

        if (! $this->isClientInbound($packet)) {
            $logger->info('OL_SOURCE_SKIP_NOT_INBOUND', [
                'chat_id' => (int) data_get($packet, 'chat.id', 0),
                'message_id' => (int) data_get($packet, 'message.id', 0),
                'system' => (string) data_get($packet, 'message.system', ''),
            ]);

            return;
        }

        $text = trim((string) data_get($packet, 'message.text', ''));
        if ($text === '') {
            return;
        }

        $rule = $this->matchRule($text);
        if ($rule === null) {
            $logger->info('OL_SOURCE_NO_MATCH', [
                'chat_id' => (int) data_get($packet, 'chat.id', 0),
                'message_id' => (int) data_get($packet, 'message.id', 0),
                'text_preview' => mb_substr($text, 0, 120),
            ]);

            return;
        }

        $chatId = (int) data_get($packet, 'chat.id', 0);
        $dealId = $this->resolveDealId($domain, $packet);
        if ($dealId <= 0) {
            $logger->info('OL_SOURCE_NO_DEAL', [
                'chat_id' => $chatId,
                'rule' => $rule['name'],
                'user_code' => $this->buildUserCode($packet),
            ]);

            return;
        }

        $ufFlag = (string) config('open_lines_source.uf_flag');
        $ufValue = (string) config('open_lines_source.uf_flag_value', 'Y');
        $categoryId = (int) config('open_lines_source.category_id', 0);

        $deal = $this->bitrixRest->postJson('crm.deal.get', ['id' => $dealId], $domain);
        $fields = is_array($deal['result'] ?? null) ? $deal['result'] : [];

        if ((int) ($fields['CATEGORY_ID'] ?? -1) !== $categoryId) {
            $logger->info('OL_SOURCE_SKIP_CATEGORY', [
                'deal_id' => $dealId,
                'category_id' => $fields['CATEGORY_ID'] ?? null,
            ]);

            return;
        }

        $stageSemantic = strtoupper(trim((string) ($fields['STAGE_SEMANTIC_ID'] ?? '')));
        $allowedSemantics = config('open_lines_source.allowed_stage_semantics', ['P']);
        if (! is_array($allowedSemantics)) {
            $allowedSemantics = ['P'];
        }
        $allowedSemantics = array_map(
            static fn ($value): string => strtoupper(trim((string) $value)),
            $allowedSemantics
        );
        if ($stageSemantic === '' || ! in_array($stageSemantic, $allowedSemantics, true)) {
            $logger->info('OL_SOURCE_SKIP_STAGE', [
                'deal_id' => $dealId,
                'stage_id' => $fields['STAGE_ID'] ?? null,
                'stage_semantic' => $stageSemantic !== '' ? $stageSemantic : null,
            ]);

            return;
        }

        $existingFlag = trim((string) ($fields[$ufFlag] ?? ''));
        if ($existingFlag !== '') {
            $logger->info('OL_SOURCE_SKIP_FLAG', ['deal_id' => $dealId]);

            return;
        }

        $existingSource = trim((string) ($fields['SOURCE_ID'] ?? ''));
        $updateFields = [
            $ufFlag => $ufValue,
        ];

        $sourceChanged = false;
        if ($existingSource === '') {
            $updateFields['SOURCE_ID'] = $rule['source_id'];
            $sourceChanged = true;
        }

        try {
            $this->bitrixRest->postJson('crm.deal.update', [
                'id' => $dealId,
                'fields' => $updateFields,
            ], $domain);

            $commentSource = $sourceChanged ? $rule['name'] : ($existingSource !== '' ? $existingSource : $rule['name']);
            $comment = $sourceChanged
                ? sprintf(
                    'Источник «%s» проставлен автоматикой по сообщению: «%s»',
                    $rule['name'],
                    $this->truncateCommentText($text)
                )
                : sprintf(
                    'Источник уже был заполнен; автоматика зафиксировала совпадение с «%s» по сообщению: «%s»',
                    $rule['name'],
                    $this->truncateCommentText($text)
                );

            $this->bitrixRest->postJson('crm.timeline.comment.add', [
                'fields' => [
                    'ENTITY_ID' => $dealId,
                    'ENTITY_TYPE' => 'deal',
                    'COMMENT' => $comment,
                ],
            ], $domain);

            $logger->info('OL_SOURCE_APPLIED', [
                'deal_id' => $dealId,
                'rule' => $rule['name'],
                'source_id' => $rule['source_id'],
                'source_changed' => $sourceChanged,
                'chat_id' => $chatId,
            ]);
        } catch (Throwable $e) {
            $logger->error('OL_SOURCE_UPDATE_FAILED', [
                'deal_id' => $dealId,
                'rule' => $rule['name'],
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Client message: not system, author is connector external user (not operator).
     *
     * @param  array<string, mixed>  $packet
     */
    private function isClientInbound(array $packet): bool
    {
        $system = strtoupper((string) data_get($packet, 'message.system', 'N'));
        if ($system === 'Y') {
            return false;
        }

        $messageUserId = (int) data_get($packet, 'message.user_id', 0);
        $connectorUserId = (int) data_get($packet, 'connector.user_id', 0);

        if ($messageUserId <= 0 || $connectorUserId <= 0) {
            return false;
        }

        return $messageUserId === $connectorUserId;
    }

    /**
     * @return array{name: string, source_id: string, patterns: list<string>}|null
     */
    private function matchRule(string $text): ?array
    {
        $haystack = mb_strtolower($text);

        foreach (config('open_lines_source.rules', []) as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $sourceId = trim((string) ($rule['source_id'] ?? ''));
            $name = trim((string) ($rule['name'] ?? ''));
            $patterns = $rule['patterns'] ?? [];
            if ($sourceId === '' || $name === '' || ! is_array($patterns)) {
                continue;
            }

            foreach ($patterns as $pattern) {
                $needle = mb_strtolower(trim((string) $pattern));
                if ($needle !== '' && str_contains($haystack, $needle)) {
                    return [
                        'name' => $name,
                        'source_id' => $sourceId,
                        'patterns' => array_values(array_map('strval', $patterns)),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $packet
     */
    private function resolveDealId(string $domain, array $packet): int
    {
        $payloads = [];
        $userCode = $this->buildUserCode($packet);
        if ($userCode !== '') {
            $payloads[] = ['USER_CODE' => $userCode];
        }

        $chatId = (int) data_get($packet, 'chat.id', 0);
        if ($chatId > 0) {
            $payloads[] = ['CHAT_ID' => $chatId];
            $payloads[] = ['DIALOG_ID' => 'chat'.$chatId];
        }

        foreach ($payloads as $payload) {
            try {
                $dialog = $this->bitrixRest->postJson('imopenlines.dialog.get', $payload, $domain);
            } catch (Throwable) {
                continue;
            }

            $result = is_array($dialog['result'] ?? null) ? $dialog['result'] : [];
            $dealId = $this->dealIdFromData((string) ($result['entity_data_2'] ?? ''));
            if ($dealId > 0) {
                return $dealId;
            }

            $dealId = $this->dealIdFromData1((string) ($result['entity_data_1'] ?? ''));
            if ($dealId > 0) {
                return $dealId;
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $packet
     */
    private function buildUserCode(array $packet): string
    {
        $connectorId = trim((string) data_get($packet, 'connector.connector_id', ''));
        $lineId = (int) data_get($packet, 'connector.line_id', 0);
        $chatId = (int) data_get($packet, 'chat.id', data_get($packet, 'connector.chat_id', 0));
        $userId = (int) data_get($packet, 'connector.user_id', data_get($packet, 'message.user_id', 0));

        if ($connectorId === '' || $lineId <= 0 || $chatId <= 0 || $userId <= 0) {
            return '';
        }

        return $connectorId.'|'.$lineId.'|'.$chatId.'|'.$userId;
    }

    private function dealIdFromData(string $entityData2): int
    {
        if ($entityData2 === '') {
            return 0;
        }

        if (preg_match('/(?:^|\|)DEAL\|(\d+)/i', $entityData2, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function dealIdFromData1(string $entityData1): int
    {
        if ($entityData1 === '') {
            return 0;
        }

        $parts = explode('|', $entityData1);
        if (count($parts) >= 3 && strcasecmp((string) $parts[1], 'DEAL') === 0) {
            return (int) $parts[2];
        }

        return 0;
    }

    private function truncateCommentText(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        if (mb_strlen($normalized) <= 400) {
            return $normalized;
        }

        return mb_substr($normalized, 0, 397).'...';
    }
}
