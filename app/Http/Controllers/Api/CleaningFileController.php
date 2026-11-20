<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BitrixOAuth;
use App\Services\BitrixRest;
use App\Services\CleaningPhotoCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class CleaningFileController extends Controller
{
    public function __construct(
        private readonly BitrixRest $bitrixRest,
        private readonly BitrixOAuth $oauth,
        private readonly CleaningPhotoCache $photoCache,
    ) {}

    /**
     * Serve a cleaning photo from local cache, otherwise redirect to Bitrix Disk
     * (keeps php-fpm free — proxying every thumbnail was exhausting the pool).
     */
    public function show(Request $request, int $fileId): BinaryFileResponse|RedirectResponse|Response
    {
        if ($fileId <= 0) {
            return response('Not found', 404);
        }

        $path = $this->photoCache->absolutePath($fileId);
        $meta = $path ? $this->photoCache->meta($fileId) : null;
        if ($path !== null && $meta !== null && is_file($path)) {
            return response()->file($path, [
                'Content-Type' => $meta['content_type'],
                'Cache-Control' => 'private, max-age=604800',
                'Content-Disposition' => 'inline; filename="'.$this->safeFilename($meta['name']).'"',
                'X-Cleaning-Photo-Cache' => 'HIT',
            ]);
        }

        $domain = $this->oauth->normalizeDomain((string) config('services.b24_tr.portal_domain'));
        if ($domain === '') {
            return response('Not configured', 500);
        }

        try {
            $remote = $this->bitrixRest->postJson('disk.file.get', ['id' => $fileId], $domain);
            $downloadUrl = trim((string) data_get($remote, 'result.DOWNLOAD_URL', ''));
            $name = trim((string) data_get($remote, 'result.NAME', 'file'));
            if ($downloadUrl === '') {
                return response('File unavailable', 404);
            }

            // Default: redirect browser straight to Bitrix (no body proxy through us).
            // Use ?proxy=1 only when we explicitly need to populate local cache.
            if (! $request->boolean('proxy')) {
                return redirect()->away($downloadUrl);
            }

            $bin = Http::timeout(12)
                ->withHeaders(['Accept' => '*/*'])
                ->get($downloadUrl);

            if (! $bin->successful() || $bin->body() === '') {
                return redirect()->away($downloadUrl);
            }

            $contentType = (string) ($bin->header('Content-Type') ?: 'application/octet-stream');
            if (! str_starts_with(strtolower($contentType), 'image/')) {
                $contentType = $this->guessImageContentType($name) ?: $contentType;
            }

            $this->photoCache->put($fileId, $bin->body(), $contentType, $name);

            return response($bin->body(), 200, [
                'Content-Type' => $contentType,
                'Cache-Control' => 'private, max-age=604800',
                'Content-Disposition' => 'inline; filename="'.$this->safeFilename($name).'"',
                'X-Cleaning-Photo-Cache' => 'MISS',
            ]);
        } catch (Throwable $e) {
            Log::channel('bitrix_cleaning')->warning('CLEANING_FILE_PROXY_FAILED', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            return response('File unavailable', 404);
        }
    }

    private function safeFilename(string $name): string
    {
        $name = preg_replace('/[\\\\\/:\*\?"<>\|]+/u', '-', $name) ?: 'file';

        return trim($name) !== '' ? $name : 'file';
    }

    private function guessImageContentType(string $name): ?string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'heic', 'heif' => 'image/heic',
            default => null,
        };
    }
}
