<?php

namespace App\Services\Disk;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DiskLocalStorage
{
    private const DELETED_PREFIX = '__deleted__';

    /**
     * Ensure local folder exists and return relative directory path.
     */
    public function ensureFolder(int $listId, string $folderPath): string
    {
        $relative = $this->folderRelativePath($listId, $folderPath);
        Storage::disk('local')->makeDirectory($relative);
        $this->ensureWebReadableDirectory($relative);

        return $relative;
    }

    /**
     * Move an existing local file to a new relative path when folder hierarchy changes.
     */
    public function moveFile(string $fromRelative, string $toRelative): string
    {
        if ($fromRelative === $toRelative) {
            return $toRelative;
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($fromRelative)) {
            return $fromRelative;
        }

        $directory = dirname($toRelative);
        if ($directory !== '.' && $directory !== '') {
            $disk->makeDirectory($directory);
            $this->ensureWebReadableDirectory($directory);
        }

        if ($disk->exists($toRelative)) {
            $disk->delete($toRelative);
        }

        $disk->move($fromRelative, $toRelative);
        $this->ensureWebReadableFile($toRelative);

        return $toRelative;
    }

    /**
     * Build active file relative path.
     */
    public function activeRelativePath(string $folderRelative, string $fieldSlug, int $fileId, string $originalName): string
    {
        return rtrim($folderRelative, '/').'/'.$this->activeFileName($fieldSlug, $fileId, $originalName);
    }

    /**
     * Build deleted file relative path (optionally with version suffix).
     */
    public function deletedRelativePath(
        string $folderRelative,
        string $fieldSlug,
        int $fileId,
        string $originalName,
        ?int $version = null
    ): string {
        return rtrim($folderRelative, '/').'/'.$this->deletedFileName($fieldSlug, $fileId, $originalName, $version);
    }

    /**
     * Download remote file into local storage path.
     */
    public function downloadTo(string $downloadUrl, string $relativePath): void
    {
        $directory = dirname($relativePath);
        if ($directory !== '.' && $directory !== '') {
            Storage::disk('local')->makeDirectory($directory);
            $this->ensureWebReadableDirectory($directory);
        }

        $absolute = Storage::disk('local')->path($relativePath);
        $response = Http::timeout(180)
            ->withOptions(['sink' => $absolute])
            ->get($downloadUrl);

        if (! $response->successful()) {
            Storage::disk('local')->delete($relativePath);

            throw new RuntimeException('Failed to download Bitrix file: HTTP '.$response->status());
        }

        $this->ensureWebReadableFile($relativePath);
    }

    /**
     * Write binary contents into local storage path.
     */
    public function putContents(string $relativePath, string $contents): void
    {
        $directory = dirname($relativePath);
        if ($directory !== '.' && $directory !== '') {
            Storage::disk('local')->makeDirectory($directory);
            $this->ensureWebReadableDirectory($directory);
        }

        Storage::disk('local')->put($relativePath, $contents);
        $this->ensureWebReadableFile($relativePath);
    }

    /**
     * Soft-mark local file as deleted by renaming with visual prefix.
     */
    public function markDeleted(string $currentRelativePath, ?int $version = null): string
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($currentRelativePath)) {
            return $currentRelativePath;
        }

        $basename = basename($currentRelativePath);
        if (str_starts_with($basename, self::DELETED_PREFIX)) {
            return $currentRelativePath;
        }

        $directory = dirname($currentRelativePath);
        $targetName = self::DELETED_PREFIX.$basename;
        if ($version !== null && $version > 0) {
            $targetName = $this->insertVersionBeforeExtension(
                self::DELETED_PREFIX.$basename,
                $version
            );
        }

        $target = ($directory === '.' ? '' : $directory.'/').$targetName;
        if ($disk->exists($target)) {
            $target = ($directory === '.' ? '' : $directory.'/').
                pathinfo($targetName, PATHINFO_FILENAME).'_'.time().
                (pathinfo($targetName, PATHINFO_EXTENSION) !== '' ? '.'.pathinfo($targetName, PATHINFO_EXTENSION) : '');
        }

        $disk->move($currentRelativePath, $target);

        return $target;
    }

    /**
     * Sanitize folder name for filesystem.
     */
    public function sanitizeFolderName(string $name): string
    {
        $name = trim($name);
        $name = str_replace(['/', '\\', "\0"], '_', $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = trim($name, " .\t\n\r\0\x0B");

        return $name !== '' ? $name : 'unnamed';
    }

    /**
     * Sanitize nested folder path and return normalized slash-separated path.
     */
    public function sanitizeFolderPath(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }

            $segments[] = $this->sanitizeFolderName($segment);
        }

        return implode('/', $segments);
    }

    /**
     * Sanitize original filename.
     */
    public function sanitizeFileName(string $name): string
    {
        $name = basename(str_replace(['\\', "\0"], '/', $name));
        $name = preg_replace('/[^\p{L}\p{N}\._\-\(\)\[\] ]+/u', '_', $name) ?? $name;
        $name = trim($name);

        return $name !== '' ? $name : 'file.bin';
    }

    private function folderRelativePath(int $listId, string $folderPath): string
    {
        $safePath = $this->sanitizeFolderPath($folderPath);
        if ($safePath === '') {
            $safePath = 'unnamed';
        }

        return 'bitrix-disk/'.$listId.'/'.$safePath;
    }

    private function ensureWebReadableDirectory(string $relativeDirectory): void
    {
        $disk = Storage::disk('local');
        $parts = explode('/', trim(str_replace('\\', '/', $relativeDirectory), '/'));
        $current = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $current = $current === '' ? $part : $current.'/'.$part;
            $absolute = $disk->path($current);
            if (! is_dir($absolute)) {
                continue;
            }

            @chmod($absolute, 0775);
            $this->tryChownWeb($absolute);
        }
    }

    private function ensureWebReadableFile(string $relativePath): void
    {
        $absolute = Storage::disk('local')->path($relativePath);
        if (! is_file($absolute)) {
            return;
        }

        @chmod($absolute, 0664);
        $this->tryChownWeb($absolute);
    }

    private function tryChownWeb(string $absolutePath): void
    {
        if (! function_exists('posix_getpwnam')) {
            return;
        }

        $user = posix_getpwnam('www-data');
        if ($user === false) {
            return;
        }

        @chown($absolutePath, (int) $user['uid']);
        @chgrp($absolutePath, (int) $user['gid']);
    }

    private function activeFileName(string $fieldSlug, int $fileId, string $originalName): string
    {
        return $fieldSlug.'_'.$fileId.'_'.$this->sanitizeFileName($originalName);
    }

    private function deletedFileName(string $fieldSlug, int $fileId, string $originalName, ?int $version): string
    {
        $base = self::DELETED_PREFIX.$this->activeFileName($fieldSlug, $fileId, $originalName);
        if ($version === null || $version <= 0) {
            return $base;
        }

        return $this->insertVersionBeforeExtension($base, $version);
    }

    private function insertVersionBeforeExtension(string $fileName, int $version): string
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $filename = pathinfo($fileName, PATHINFO_FILENAME);

        if ($extension === '') {
            return $filename.'_v'.$version;
        }

        return $filename.'_v'.$version.'.'.$extension;
    }
}
