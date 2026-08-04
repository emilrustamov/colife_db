<?php

namespace App\Console\Commands;

use App\Models\DiskSyncedFile;
use App\Services\BitrixWebhook;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CleanupRentFolders extends Command
{
    protected $signature = 'bitrix:cleanup-old-rent-folders
                            {--execute : Actually delete in Bitrix Disk and local storage}
                            {--list-id=322 : Local list id for synced files}
                            {--sleep-ms=350 : Pause between Bitrix delete calls}
                            {--bitrix-scan : Also scan Bitrix Appartments tree (slow, rate-limited)}
                            {--appartments-id=2156712 : Bitrix Appartments root folder id}';

    protected $description = '[OPS one-off] Remove old-format stay folders under Appartments/*/Rent (dates before surname). Not scheduled; dry-run by default.';

    private const OLD_FORMAT = '/_\d{2}\.\d{2}\.\d{4}_\d{2}\.\d{2}\.\d{4}_.+/u';

    /**
     * Execute the console command.
     */
    public function handle(BitrixWebhook $client): int
    {
        $execute = (bool) $this->option('execute');
        $listId = (int) $this->option('list-id');
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $webhook = $client->diskWebhook();

        $this->info($execute ? 'EXECUTE mode' : 'DRY-RUN mode (pass --execute to delete)');

        $oldFolders = $this->collectFromLocalDb($listId);
        $this->info('old_format from local db: '.count($oldFolders));

        if ((bool) $this->option('bitrix-scan')) {
            $fromBitrix = $this->collectFromBitrix($client, $webhook, (int) $this->option('appartments-id'), $sleepMs);
            $byId = [];
            foreach ($oldFolders as $folder) {
                $byId[(int) $folder['folder_id']] = $folder;
            }
            foreach ($fromBitrix as $folder) {
                $byId[(int) $folder['folder_id']] = $folder;
            }
            $oldFolders = array_values($byId);
            $this->info('old_format after bitrix scan merge: '.count($oldFolders));
        }

        $reportPath = storage_path('app/old_rent_folders.json');
        file_put_contents($reportPath, json_encode($oldFolders, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->info("report={$reportPath}");

        foreach ($oldFolders as $folder) {
            $this->line('OLD '.$folder['folder_id'].' | '.$folder['local_path']);
        }

        if (! $execute || $oldFolders === []) {
            return self::SUCCESS;
        }

        $deletedBitrix = 0;
        $failedBitrix = 0;
        $deletedLocalRows = 0;
        $deletedLocalFiles = 0;

        foreach ($oldFolders as $folder) {
            $folderId = (int) $folder['folder_id'];
            $localPath = (string) $folder['local_path'];

            if ($folderId > 0) {
                $ok = $this->deleteBitrixFolderTree($client, $webhook, $folderId, $sleepMs);
                if ($ok) {
                    $deletedBitrix++;
                    $this->line("bitrix deleted {$folderId}");
                } else {
                    $failedBitrix++;
                    $this->error("bitrix failed {$folderId} {$localPath}");
                }
            }

            $rows = DiskSyncedFile::query()
                ->where('list_id', $listId)
                ->where(function ($q) use ($localPath): void {
                    $q->where('folder_name', $localPath)
                        ->orWhere('folder_name', 'like', $localPath.'/%');
                })
                ->get(['id', 'local_path']);

            foreach ($rows as $row) {
                if (is_string($row->local_path) && $row->local_path !== '' && Storage::disk('local')->exists($row->local_path)) {
                    Storage::disk('local')->delete($row->local_path);
                    $deletedLocalFiles++;
                }
            }

            $deletedLocalRows += DiskSyncedFile::query()
                ->whereIn('id', $rows->pluck('id'))
                ->delete();

            $fsDir = storage_path('app/private/bitrix-disk/'.$listId.'/'.$localPath);
            if (is_dir($fsDir)) {
                $this->removeDirectory($fsDir);
            }
        }

        $this->info("deleted_bitrix={$deletedBitrix} failed_bitrix={$failedBitrix}");
        $this->info("deleted_local_rows={$deletedLocalRows} deleted_local_files={$deletedLocalFiles}");

        return self::SUCCESS;
    }

    /**
     * @return list<array{folder_id:int, local_path:string, folder_name:string, source:string}>
     */
    private function collectFromLocalDb(int $listId): array
    {
        $rows = DiskSyncedFile::query()
            ->where('list_id', $listId)
            ->where('folder_name', 'like', 'Appartments/%/Rent/%')
            ->selectRaw('folder_name, MAX(folder_bitrix_id) as folder_bitrix_id, COUNT(*) as c')
            ->groupBy('folder_name')
            ->orderBy('folder_name')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $path = (string) $row->folder_name;
            $leaf = basename(str_replace('\\', '/', $path));
            if (! $this->isOldFormat($leaf)) {
                continue;
            }

            $out[] = [
                'folder_id' => (int) ($row->folder_bitrix_id ?? 0),
                'local_path' => $path,
                'folder_name' => $leaf,
                'source' => 'local_db',
                'rows' => (int) $row->c,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{folder_id:int, local_path:string, folder_name:string, source:string}>
     */
    private function collectFromBitrix(
        BitrixWebhook $client,
        string $webhook,
        int $rootId,
        int $sleepMs
    ): array {
        $this->info("Bitrix scan Appartments id={$rootId}");
        $apartments = $this->listFolders($client, $webhook, $rootId, $sleepMs);
        $this->info('Apartments: '.count($apartments));

        $old = [];
        $scanned = 0;
        foreach ($apartments as $apartment) {
            $scanned++;
            $rent = $this->findChildFolder($client, $webhook, (int) $apartment['ID'], 'Rent', $sleepMs);
            if ($rent === null) {
                continue;
            }

            foreach ($this->listFolders($client, $webhook, (int) $rent['ID'], $sleepMs) as $child) {
                $name = (string) ($child['NAME'] ?? '');
                if ($name === '' || ! $this->isOldFormat($name)) {
                    continue;
                }

                $aptName = (string) ($apartment['NAME'] ?? '');
                $old[] = [
                    'folder_id' => (int) $child['ID'],
                    'local_path' => 'Appartments/'.$aptName.'/Rent/'.$name,
                    'folder_name' => $name,
                    'source' => 'bitrix',
                ];
            }

            if ($scanned % 20 === 0) {
                $this->line("bitrix scanned={$scanned}/".count($apartments).' old='.count($old));
            }
        }

        return $old;
    }

    private function isOldFormat(string $name): bool
    {
        return preg_match(self::OLD_FORMAT, $name) === 1;
    }

    private function deleteBitrixFolderTree(
        BitrixWebhook $client,
        string $webhook,
        int $folderId,
        int $sleepMs
    ): bool {
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $response = $client->callRaw('disk.folder.deletetree', ['id' => $folderId], $webhook);
            $error = (string) ($response['error'] ?? '');

            if ($error === '') {
                $this->sleepMs($sleepMs);

                return true;
            }

            if ($error === 'QUERY_LIMIT_EXCEEDED') {
                usleep(1_000_000 * $attempt);
                continue;
            }

            if (in_array($error, ['ERROR_NOT_FOUND', 'DISK_ERROR', ''], true)) {
                $desc = (string) ($response['error_description'] ?? '');
                if (str_contains(strtolower($desc), 'not found') || $error === 'ERROR_NOT_FOUND') {
                    $this->sleepMs($sleepMs);

                    return true;
                }
            }

            $this->warn("folder {$folderId}: {$error} ".($response['error_description'] ?? ''));
            $this->sleepMs($sleepMs);

            return false;
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listFolders(
        BitrixWebhook $client,
        string $webhook,
        int $parentId,
        int $sleepMs
    ): array {
        $folders = [];
        $start = 0;

        while (true) {
            $response = $this->callWithRetry($client, $webhook, 'disk.folder.getchildren', [
                'id' => $parentId,
                'start' => $start,
            ], $sleepMs);

            $items = data_get($response, 'result', []);
            if (! is_array($items) || $items === []) {
                break;
            }

            foreach ($items as $item) {
                if (! is_array($item) || ($item['TYPE'] ?? '') !== 'folder') {
                    continue;
                }
                $folders[] = $item;
            }

            $next = data_get($response, 'next');
            if ($next === null && count($items) < 50) {
                break;
            }

            $start = $next !== null ? (int) $next : $start + count($items);
            if ($start > 30000) {
                break;
            }
        }

        return $folders;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findChildFolder(
        BitrixWebhook $client,
        string $webhook,
        int $parentId,
        string $name,
        int $sleepMs
    ): ?array {
        foreach ($this->listFolders($client, $webhook, $parentId, $sleepMs) as $folder) {
            if (($folder['NAME'] ?? '') === $name) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function callWithRetry(
        BitrixWebhook $client,
        string $webhook,
        string $method,
        array $payload,
        int $sleepMs
    ): array {
        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $response = $client->callRaw($method, $payload, $webhook);
            $error = (string) ($response['error'] ?? '');
            if ($error !== 'QUERY_LIMIT_EXCEEDED') {
                $this->sleepMs($sleepMs);

                return $response;
            }
            usleep(800_000 * $attempt);
        }

        return $response ?? [];
    }

    private function sleepMs(int $sleepMs): void
    {
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
        }

        @rmdir($path);
    }
}
