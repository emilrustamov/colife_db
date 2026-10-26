<?php

namespace App\Services;

class SupervisorHealth
{
    private const PROGRAMS = [
        'laravel-queue-default',
        'laravel-queue-pauses',
    ];

    public function __construct(
        private readonly TelegramAlert $alerts,
        private readonly TelegramAlertText $text,
    ) {}

    /**
     * Check supervisor queue workers are RUNNING.
     *
     * @return list<string>
     */
    public function check(): array
    {
        $status = $this->supervisorStatus();
        if ($status === null) {
            $message = $this->text->message(
                '⚙️',
                'Supervisor отвалился',
                null,
                'Не удалось проверить воркеры очередей.',
            );
            $this->alerts->notifyOnce('supervisor_unreadable', $message);

            return [$message];
        }

        foreach (self::PROGRAMS as $program) {
            $matches = array_values(array_filter(
                $status,
                static fn (array $row): bool => str_starts_with($row['name'], $program)
            ));

            if ($matches === []) {
                $message = $this->text->message(
                    '⚙️',
                    'Воркеры очереди отвалились',
                    null,
                    'Паузы и фоновые job не обрабатываются.',
                );
                $this->alerts->notifyOnce('supervisor_queue_down', $message);

                return [$message];
            }

            foreach ($matches as $row) {
                if (strtoupper($row['state']) !== 'RUNNING') {
                    $message = $this->text->message(
                        '⚙️',
                        'Воркеры очереди отвалились',
                        null,
                        'Паузы и фоновые job не обрабатываются.',
                    );
                    $this->alerts->notifyOnce('supervisor_queue_down', $message);

                    return [$message];
                }
            }
        }

        return [];
    }

    /**
     * @return list<array{name: string, state: string, detail: string}>|null
     */
    private function supervisorStatus(): ?array
    {
        $output = [];
        $code = 0;
        exec('supervisorctl status 2>/dev/null', $output, $code);
        if ($code !== 0 && $output === []) {
            return null;
        }

        $rows = [];
        foreach ($output as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! preg_match('/^(\S+)\s+(\S+)\s*(.*)$/', $line, $m)) {
                continue;
            }
            $rows[] = [
                'name' => $m[1],
                'state' => $m[2],
                'detail' => trim($m[3]),
            ];
        }

        return $rows;
    }
}
