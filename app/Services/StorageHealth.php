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
    ];

    public function __construct(
        private readonly TelegramAlert $alerts,
        private readonly TelegramAlertText $text,
    ) {}

    /**
     * Ensure Laravel storage dirs are traversable/writable; repair mode if possible.
     *
     * @return list<string>
     */
    public function check(): array
    {
        $broken = [];

        foreach (self::PATHS as $relative) {
            $path = storage_path($relative);
            if (! is_dir($path)) {
                if (@mkdir($path, 0775, true)) {
                    @chown($path, 'www-data');
                    @chgrp($path, 'www-data');
                } else {
                    $broken[] = $relative.' (missing)';
                }

                continue;
            }

            $mode = fileperms($path) & 0777;
            $needsExec = ($mode & 0111) !== 0111;
            $writable = is_writable($path);
            $probe = $path.'/.storage_health_probe';

            if (! $needsExec && $writable) {
                if (@file_put_contents($probe, 'ok') !== false) {
                    @unlink($probe);

                    continue;
                }
            }

            if (@chmod($path, 0775)) {
                @chown($path, 'www-data');
                @chgrp($path, 'www-data');
                clearstatcache(true, $path);

                if (is_writable($path) && @file_put_contents($probe, 'ok') !== false) {
                    @unlink($probe);

                    continue;
                }
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
