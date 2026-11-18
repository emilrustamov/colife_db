<?php

namespace App\Console\Commands;

use App\Services\BitrixRest;
use App\Services\OlDealSource;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class OpenLinesBackfillSourcesCommand extends Command
{
    protected $signature = 'open-lines:backfill-sources
        {--since=2026-11-16 : ISO date/time lower bound for DATE_CREATE}
        {--deal= : Process only this deal id}
        {--limit=0 : Max deals to process (0 = no limit)}
        {--dry-run : Match and report without writing}';

    protected $description = '[OPS] Backfill SOURCE_ID / promo on HK OL deals missed while webhooks were rejected.';

    public function __construct(
        private readonly BitrixRest $bitrixRest,
        private readonly OlDealSource $olDealSource
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $domain = trim((string) config('open_lines_source.portal_domain'));
        if ($domain === '') {
            $this->error('open_lines_source.portal_domain is empty');

            return self::FAILURE;
        }

        $since = Carbon::parse((string) $this->option('since'));
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $onlyDeal = (int) $this->option('deal');

        $dealIds = $onlyDeal > 0
            ? [$onlyDeal]
            : $this->candidateDealIds($domain, $since, $limit);

        if ($dealIds === []) {
            $this->info('No candidate deals.');

            return self::SUCCESS;
        }

        $stats = [
            'checked' => 0,
            'applied' => 0,
            'no_match' => 0,
            'no_session' => 0,
            'no_message' => 0,
            'skip_category' => 0,
            'skip_stage' => 0,
            'skip_flag' => 0,
            'errors' => 0,
        ];

        foreach ($dealIds as $dealId) {
            $stats['checked']++;
            try {
                $status = $this->processDeal($domain, $dealId, $dryRun);
            } catch (Throwable $e) {
                $stats['errors']++;
                $this->error(sprintf('deal %d error: %s', $dealId, $e->getMessage()));

                continue;
            }

            $stats[$status] = ($stats[$status] ?? 0) + 1;
            $this->line(sprintf('deal %d → %s', $dealId, $status));
        }

        $this->info(sprintf(
            'Done. checked=%d applied=%d no_match=%d no_session=%d no_message=%d skip_flag=%d skip_category=%d skip_stage=%d errors=%d mode=%s',
            $stats['checked'],
            $stats['applied'],
            $stats['no_match'],
            $stats['no_session'],
            $stats['no_message'],
            $stats['skip_flag'],
            $stats['skip_category'],
            $stats['skip_stage'],
            $stats['errors'],
            $dryRun ? 'dry-run' : 'write'
        ));

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function candidateDealIds(string $domain, Carbon $since, int $limit): array
    {
        $ufFlag = (string) config('open_lines_source.uf_flag');
        $categories = $this->targetCategoryIds();
        $ids = [];

        foreach ($categories as $categoryId) {
            $start = 0;
            do {
                $response = $this->bitrixRest->postJson('crm.deal.list', [
                    'filter' => [
                        'CATEGORY_ID' => $categoryId,
                        'STAGE_SEMANTIC_ID' => 'P',
                        '>=DATE_CREATE' => $since->toIso8601String(),
                    ],
                    'select' => ['ID', 'SOURCE_ID', 'CATEGORY_ID', 'STAGE_SEMANTIC_ID', $ufFlag, 'DATE_CREATE'],
                    'order' => ['ID' => 'ASC'],
                    'start' => $start,
                ], $domain);

                foreach ($response['result'] ?? [] as $deal) {
                    if (! is_array($deal)) {
                        continue;
                    }

                    $flag = trim((string) ($deal[$ufFlag] ?? ''));
                    if ($flag !== '') {
                        continue;
                    }

                    $sourceId = trim((string) ($deal['SOURCE_ID'] ?? ''));
                    if ($sourceId !== '' && ! $this->olDealSource->isConnectorSourceId($sourceId)) {
                        continue;
                    }

                    $ids[] = (int) $deal['ID'];
                    if ($limit > 0 && count($ids) >= $limit) {
                        return $ids;
                    }
                }

                $start = array_key_exists('next', $response) ? (int) $response['next'] : -1;
            } while ($start >= 0);
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function targetCategoryIds(): array
    {
        $ids = [(int) config('open_lines_source.category_id', 0)];
        foreach (config('open_lines_source.rules', []) as $rule) {
            if (! is_array($rule) || ! array_key_exists('category_id', $rule)) {
                continue;
            }
            $ids[] = (int) $rule['category_id'];
        }

        return array_values(array_unique($ids));
    }

    private function processDeal(string $domain, int $dealId, bool $dryRun): string
    {
        $sessionId = $this->resolveSessionId($domain, $dealId);
        if ($sessionId <= 0) {
            return 'no_session';
        }

        [$lineId, $text] = $this->firstClientMessage($domain, $sessionId);
        if ($lineId <= 0 || $text === '') {
            return 'no_message';
        }

        $rule = $this->olDealSource->matchTextRule($text, $lineId);
        if ($rule === null) {
            return 'no_match';
        }

        if ($dryRun) {
            $this->line(sprintf(
                '  dry-run match rule=%s line=%d preview=%s',
                $rule['name'],
                $lineId,
                mb_substr($text, 0, 80)
            ));

            return 'applied';
        }

        return $this->olDealSource->applyTextToDeal($domain, $dealId, $text, $lineId);
    }

    private function resolveSessionId(string $domain, int $dealId): int
    {
        $response = $this->bitrixRest->postJson('crm.activity.list', [
            'filter' => [
                'OWNER_TYPE_ID' => 2,
                'OWNER_ID' => $dealId,
                'PROVIDER_ID' => 'IMOPENLINES_SESSION',
            ],
            'order' => ['ID' => 'ASC'],
            'select' => ['ID', 'ASSOCIATED_ENTITY_ID', 'PROVIDER_ID'],
        ], $domain);

        foreach ($response['result'] ?? [] as $activity) {
            $sessionId = (int) ($activity['ASSOCIATED_ENTITY_ID'] ?? 0);
            if ($sessionId > 0) {
                return $sessionId;
            }
        }

        return 0;
    }

    /**
     * @return array{0: int, 1: string} lineId, text
     */
    private function firstClientMessage(string $domain, int $sessionId): array
    {
        $response = $this->bitrixRest->postJson('imopenlines.session.history.get', [
            'SESSION_ID' => $sessionId,
            'LIMIT' => 200,
        ], $domain);

        $result = is_array($response['result'] ?? null) ? $response['result'] : [];
        $messages = is_array($result['message'] ?? null) ? $result['message'] : [];
        $users = is_array($result['users'] ?? null) ? $result['users'] : [];
        $chats = is_array($result['chat'] ?? null) ? $result['chat'] : [];

        $lineId = 0;
        foreach ($chats as $chat) {
            if (! is_array($chat)) {
                continue;
            }
            $entityId = (string) ($chat['entityId'] ?? '');
            $parts = explode('|', $entityId);
            if (count($parts) >= 2 && ctype_digit((string) $parts[1])) {
                $lineId = (int) $parts[1];
                break;
            }
        }
        if ($lineId <= 0) {
            $lineId = (int) config('open_lines_source.line_id', 5);
        }

        uksort($messages, static fn ($a, $b): int => ((int) $a) <=> ((int) $b));

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $senderId = (string) ($message['senderid'] ?? '');
            if ($senderId === '' || $senderId === '0') {
                continue;
            }

            $user = is_array($users[$senderId] ?? null) ? $users[$senderId] : [];
            if (empty($user['connector'])) {
                continue;
            }

            $text = trim((string) ($message['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            // Strip delivery icons / BB-code noise that does not affect substring match.
            $text = preg_replace('/\[icon=[^\]]*\]/iu', '', $text) ?? $text;
            $text = trim($text);
            if ($text === '') {
                continue;
            }

            return [$lineId, $text];
        }

        return [0, ''];
    }
}
