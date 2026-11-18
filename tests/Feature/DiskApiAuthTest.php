<?php

namespace Tests\Feature;

use App\Jobs\SyncDiskJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DiskApiAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.client_balance.api_key', 'test-disk-key');
        config()->set('services.bitrix.lists.disk_iblock_id', 322);
        Queue::fake();
    }

    public function test_disk_pull_accepts_key_query_param(): void
    {
        $this->mock(\App\Services\DiskSync::class, function ($mock): void {
            $mock->shouldReceive('sync')
                ->once()
                ->with(322, 1500024)
                ->andReturn([
                    'downloaded' => 0,
                    'unchanged' => 1,
                    'marked_deleted' => 0,
                    'failed' => 0,
                ]);
        });

        $response = $this->post('/api/disk/pull?key=test-disk-key&id=1500024');

        $response->assertOk()->assertJson([
            'success' => true,
            'element_id' => 1500024,
        ]);
        Queue::assertPushed(SyncDiskJob::class);
    }

    public function test_disk_pull_rejects_missing_key(): void
    {
        $response = $this->post('/api/disk/pull?id=1500024');

        $response->assertUnauthorized();
    }
}
