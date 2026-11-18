<?php

namespace Tests\Unit;

use App\Services\BitrixRest;
use App\Services\Profiles\ApartmentProfile;
use App\Support\BitrixSync;
use Tests\TestCase;

class SpaProfileExistingSelectTest extends TestCase
{
    public function test_existing_select_includes_business_log_keys(): void
    {
        $profile = new ApartmentProfile(
            $this->createMock(BitrixRest::class),
            $this->createMock(BitrixSync::class),
        );

        $method = new \ReflectionMethod($profile, 'existingSelectColumns');
        $columns = $method->invoke($profile);

        $this->assertContains('id', $columns);
        $this->assertContains('bitrix_id', $columns);
        $this->assertContains('bitrix_updated_at', $columns);
        $this->assertContains('title', $columns);
        $this->assertContains('stage_id', $columns);
        $this->assertContains('is_deleted', $columns);
    }
}
