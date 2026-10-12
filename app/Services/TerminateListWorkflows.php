<?php

namespace App\Services;

class TerminateListWorkflows
{
    public function __construct(
        private readonly BitrixWebhook $webhookClient,
    ) {}

    /**
     * Stop running list BPs for an element, then start template 3666 on it.
     *
     * @return array{
     *     element_id: int,
     *     found: int,
     *     terminated: int,
     *     failed: int,
     *     started_id: ?string,
     *     start_error: ?string,
     *     workflows: list<array{id: string, template_id: mixed, terminated: bool, error: ?string}>
     * }
     */
    public function run(int $elementId, ?string $status = null): array
    {
        $statusText = $status !== null && $status !== ''
            ? $status
            : 'Terminated via colife_db API';

        $instances = $this->instances($elementId);
        $workflows = [];
        $terminated = 0;
        $failed = 0;

        foreach ($instances as $instance) {
            $id = trim((string) ($instance['ID'] ?? ''));
            if ($id === '') {
                continue;
            }

            $response = $this->webhookClient->callRaw('bizproc.workflow.terminate', [
                'ID' => $id,
                'STATUS' => $statusText,
            ], $this->webhookClient->bpWebhook());

            $ok = data_get($response, 'result') === true;
            if ($ok) {
                $terminated++;
            } else {
                $failed++;
            }

            $workflows[] = [
                'id' => $id,
                'template_id' => $instance['TEMPLATE_ID'] ?? null,
                'terminated' => $ok,
                'error' => $ok ? null : (string) (data_get($response, 'error_description') ?: data_get($response, 'error') ?: 'terminate failed'),
            ];
        }

        [$startedId, $startError] = $this->start($elementId);
        if ($startError !== null) {
            $failed++;
        }

        return [
            'element_id' => $elementId,
            'found' => count($workflows),
            'terminated' => $terminated,
            'failed' => $failed,
            'started_id' => $startedId,
            'start_error' => $startError,
            'workflows' => $workflows,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function start(int $elementId): array
    {
        $templateId = (int) config('services.bitrix.bp_list_template_id', 3666);
        if ($templateId <= 0) {
            $templateId = 3666;
        }

        $response = $this->webhookClient->callRaw('bizproc.workflow.start', [
            'TEMPLATE_ID' => $templateId,
            'DOCUMENT_ID' => [
                'lists',
                'Bitrix\\Lists\\BizprocDocumentLists',
                (string) $elementId,
            ],
        ], $this->webhookClient->bpWebhook());

        $startedId = data_get($response, 'result');
        if (is_string($startedId) && $startedId !== '') {
            return [$startedId, null];
        }

        $error = (string) (data_get($response, 'error_description') ?: data_get($response, 'error') ?: 'start failed');

        return [null, $error];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function instances(int $elementId): array
    {
        $items = [];
        $start = 0;

        do {
            $response = $this->webhookClient->callRaw('bizproc.workflow.instances', [
                'select' => ['ID', 'MODULE_ID', 'ENTITY', 'DOCUMENT_ID', 'TEMPLATE_ID', 'STARTED'],
                'filter' => [
                    'MODULE_ID' => 'lists',
                    'DOCUMENT_ID' => (string) $elementId,
                ],
                'order' => ['STARTED' => 'DESC'],
                'start' => $start,
            ], $this->webhookClient->bpWebhook());

            $error = data_get($response, 'error');
            if (is_string($error) && $error !== '') {
                throw new \RuntimeException((string) (data_get($response, 'error_description') ?: $error));
            }

            $page = data_get($response, 'result', []);
            if (! is_array($page)) {
                break;
            }

            foreach ($page as $row) {
                if (is_array($row)) {
                    $items[] = $row;
                }
            }

            $next = data_get($response, 'next');
            $start = is_numeric($next) ? (int) $next : null;
        } while ($start !== null);

        return $items;
    }
}
