<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CleaningPhotoCache
{
    private const DISK = 'local';

    private const DIR = 'cleaning/photos';

    /**
     * Store a Bitrix disk photo locally for History thumbnails.
     */
    public function put(int $fileId, string $binary, string $contentType = 'image/jpeg', string $name = ''): bool
    {
        if ($fileId <= 0 || $binary === '') {
            return false;
        }

        try {
            $this->ensureDir();
            $ok = Storage::disk(self::DISK)->put($this->binPath($fileId), $binary);
            if (! $ok) {
                return false;
            }

            Storage::disk(self::DISK)->put($this->metaPath($fileId), json_encode([
                'content_type' => $this->normalizeContentType($contentType, $name),
                'name' => $this->safeName($name, $fileId),
                'size' => strlen($binary),
                'cached_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE));

            return true;
        } catch (Throwable $e) {
            Log::channel('bitrix_cleaning')->warning('CLEANING_PHOTO_CACHE_PUT_FAILED', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Absolute filesystem path for a cached binary, if present.
     */
    public function absolutePath(int $fileId): ?string
    {
        if (! $this->has($fileId)) {
            return null;
        }

        return Storage::disk(self::DISK)->path($this->binPath($fileId));
    }

    /**
     * @return array{content_type: string, name: string}|null
     */
    public function meta(int $fileId): ?array
    {
        if ($fileId <= 0 || ! $this->has($fileId)) {
            return null;
        }

        return $this->readMeta($fileId);
    }

    /**
     * @return array{binary: string, content_type: string, name: string}|null
     */
    public function get(int $fileId): ?array
    {
        if ($fileId <= 0 || ! $this->has($fileId)) {
            return null;
        }

        try {
            $binary = Storage::disk(self::DISK)->get($this->binPath($fileId));
            if (! is_string($binary) || $binary === '') {
                return null;
            }

            $meta = $this->readMeta($fileId);

            return [
                'binary' => $binary,
                'content_type' => $meta['content_type'],
                'name' => $meta['name'],
            ];
        } catch (Throwable $e) {
            Log::channel('bitrix_cleaning')->warning('CLEANING_PHOTO_CACHE_GET_FAILED', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function has(int $fileId): bool
    {
        if ($fileId <= 0) {
            return false;
        }

        return Storage::disk(self::DISK)->exists($this->binPath($fileId));
    }

    public function forget(int $fileId): void
    {
        if ($fileId <= 0) {
            return;
        }

        try {
            Storage::disk(self::DISK)->delete([
                $this->binPath($fileId),
                $this->metaPath($fileId),
            ]);
        } catch (Throwable $e) {
            Log::channel('bitrix_cleaning')->warning('CLEANING_PHOTO_CACHE_FORGET_FAILED', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<int>  $fileIds
     */
    public function forgetMany(array $fileIds): void
    {
        foreach ($fileIds as $fileId) {
            $this->forget((int) $fileId);
        }
    }

    private function ensureDir(): void
    {
        if (! Storage::disk(self::DISK)->exists(self::DIR)) {
            Storage::disk(self::DISK)->makeDirectory(self::DIR);
        }
    }

    private function binPath(int $fileId): string
    {
        return self::DIR.'/'.$fileId.'.bin';
    }

    private function metaPath(int $fileId): string
    {
        return self::DIR.'/'.$fileId.'.json';
    }

    /**
     * @return array{content_type: string, name: string}
     */
    private function readMeta(int $fileId): array
    {
        $defaults = [
            'content_type' => 'image/jpeg',
            'name' => 'photo_'.$fileId.'.jpg',
        ];

        try {
            if (! Storage::disk(self::DISK)->exists($this->metaPath($fileId))) {
                return $defaults;
            }
            $raw = Storage::disk(self::DISK)->get($this->metaPath($fileId));
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (! is_array($decoded)) {
                return $defaults;
            }

            return [
                'content_type' => $this->normalizeContentType(
                    (string) ($decoded['content_type'] ?? 'image/jpeg'),
                    (string) ($decoded['name'] ?? '')
                ),
                'name' => $this->safeName((string) ($decoded['name'] ?? ''), $fileId),
            ];
        } catch (Throwable) {
            return $defaults;
        }
    }

    private function normalizeContentType(string $contentType, string $name): string
    {
        $contentType = strtolower(trim(explode(';', $contentType)[0] ?? ''));
        if (str_starts_with($contentType, 'image/')) {
            return $contentType;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'heic', 'heif' => 'image/heic',
            default => 'image/jpeg',
        };
    }

    private function safeName(string $name, int $fileId): string
    {
        $name = preg_replace('/[\\\\\/:\*\?"<>\|]+/u', '-', $name) ?: '';
        $name = trim($name);

        return $name !== '' ? $name : ('photo_'.$fileId.'.jpg');
    }
}
