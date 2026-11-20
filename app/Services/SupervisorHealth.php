<?php

namespace App\Services;

class SupervisorHealth
{
    private const PROGRAMS = [
        'horizon',
    ];

    /** @var list<string> */
    private const SUPERVISOR_STATES = [
        'RUNNING',
        'STARTING',
        'STOPPED',
        'STOPPING',
        'BACKOFF',
        'FATAL',
        'EXITED',
        'UNKNOWN',
    ];

    public function __construct(
        private readonly TelegramAlert $alerts,
        private readonly TelegramAlertText $text,
    ) {}

    /**
     * Check Horizon is alive. Prefer supervisorctl; fall back to artisan
     * when the schedule user cannot read supervisor.sock (www-data + chmod 0700).
     *
     * @return list<string>
     */
    public function check(): array
    {
        $status = $this->supervisorStatus();
        if ($status !== null) {
            foreach (self::PROGRAMS as $program) {
                $matches = array_values(array_filter(
                    $status,
                    static fn (array $row): bool => str_starts_with($row['name'], $program)
                ));

                if ($matches === []) {
                    break;
                }

                foreach ($matches as $row) {
                    if (strtoupper($row['state']) !== 'RUNNING') {
                        return $this->alertDown();
                    }
                }

                return [];
            }
        }

        if ($this->horizonIsRunning()) {
            return [];
        }

        return $this->alertDown();
    }

    /**
     * @return list<string>
     */
    private function alertDown(): array
    {
        $message = $this->text->message(
            '⚙️',
            'Horizon отвалился',
            null,
            'Очереди не обрабатываются.',
        );
        $this->alerts->notifyOnce('supervisor_queue_down', $message);

        return [$message];
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

            $state = strtoupper($m[2]);
            // Permission / XML-RPC errors print on stdout and must not look like programs.
            if (! in_array($state, self::SUPERVISOR_STATES, true)) {
                continue;
            }

            $rows[] = [
                'name' => $m[1],
                'state' => $state,
                'detail' => trim($m[3]),
            ];
        }

        return $rows === [] ? null : $rows;
    }

    private function horizonIsRunning(): bool
    {
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $cmd = escapeshellarg($php).' '.escapeshellarg(base_path('artisan')).' horizon:status --no-ansi 2>/dev/null';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $text = implode("\n", $output);

        return $code === 0 && str_contains($text, 'Horizon is running');
    }
}
