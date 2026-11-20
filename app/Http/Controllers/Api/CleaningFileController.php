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
use Throwable;

class CleaningFileController extends Controller
{
    public function __construct(
        private readonly BitrixRest $bitrixRest,
        private readonly BitrixOAuth $oauth,
        private readonly CleaningPhotoCache $photoCache,
    ) {}

    /**
     * Serve a cleaning photo from local cache, otherwise proxy Bitrix Disk once and cache it.
     */
    public function show(Request $request, int $fileId): RedirectResponse|Response
    {
        if ($fileId <= 0) {
            return response('Not found', 404);
        }

        $cached = $this->photoCache->get($fileId);
        if ($cached !== null) {
            return $this->imageResponse($cached['binary'], $cached['content_type'], $cached['name'], true);
        }

        $domain = $this->oauth->normalizeDomain((string) config('services.b24_tr.portal_domain'));
        if ($domain === '') {
            return response('Not configured', 500);
        }

        try {
            $meta = $this->bitrixRest->postJson('disk.file.get', ['id' => $fileId], $domain);
            $downloadUrl = trim((string) data_get($meta, 'result.DOWNLOAD_URL', ''));
            $name = trim((string) data_get($meta, 'result.NAME', 'file'));
            if ($downloadUrl === '') {
                return response('File unavailable', 404);
            }

            if ($request->boolean('redirect')) {
                return redirect()->away($downloadUrl);
            }

            $bin = Http::timeout(30)
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

            return $this->imageResponse($bin->body(), $contentType, $name, false);
        } catch (Throwable $e) {
            Log::channel('bitrix_cleaning')->warning('CLEANING_FILE_PROXY_FAILED', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            return response('File unavailable', 404);
        }
    }

    private function imageResponse(string $binary, string $contentType, string $name, bool $fromCache): Response
    {
        return response($binary, 200, [
            'Content-Type' => $contentType,
            // File IDs are immutable; browser may keep for a week. Local disk cache is the source of truth.
            'Cache-Control' => 'private, max-age=604800',
            'Content-Disposition' => 'inline; filename="'.$this->safeFilename($name).'"',
            'X-Cleaning-Photo-Cache' => $fromCache ? 'HIT' : 'MISS',
        ]);
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
