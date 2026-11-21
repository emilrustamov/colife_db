<?php

namespace App\Services;

class StorageHealth
{
    /**
     * @var list<string>
     */
    private const PATHS = [
        'logs',
        'framework',
        'framework/cache',
        'framework/sessions',
        'framework/views',
        'app',
        'app/cleaning',
        'app/cleaning/photos',
    ];

    public function __construct(
        private readonly TelegramAlert $alerts,
        private readonly TelegramAlertText $text,
    ) {}

    /**
     * Report if Laravel storage dirs are not writable. Does not chmod/chown —
     * writers (php-fpm / schedule / horizon) must all run as www-data.
     *
     * @return list<string>
     */
    public function check(): array
    {
        $broken = [];

        foreach (self::PATHS as $relative) {
            $path = storage_path($relative);
            if (! is_dir($path)) {
                $broken[] = $relative.' (missing)';

                continue;
            }

            $mode = fileperms($path) & 0777;
            $writable = is_writable($path);
            $probe = $path.'/.storage_health_probe';

            if ($writable && @file_put_contents($probe, 'ok') !== false) {
                @unlink($probe);

                continue;
            }

            $broken[] = sprintf('%s (%04o, writable=%s)', $relative, $mode, $writable ? 'yes' : 'no');
        }

        if ($broken === []) {
            return [];
        }

        $message = $this->text->message(
            '📂',
            'Storage rights broken',
            null,
            implode(', ', $broken),
        );
        $this->alerts->notifyOnce('storage_rights_broken', $message);

        return [$message];
    }
}
