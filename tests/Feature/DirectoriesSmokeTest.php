<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DirectoriesSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_named_directory_pages_are_accessible_for_admin(): void
    {
        $keys = [
            'users',
            'roles',
            'contact-types',
            'contacts',
            'contact-phones',
            'contact-emails',
            'metro-stations',
            'apartment-types',
            'pipelines',
            'stages',
            'buildings',
            'apartments',
            'units',
            'unit-stays',
            'bitrix-units-snapshot',
        ];

        foreach ($keys as $key) {
            $this->actingAs($this->admin)
                ->get("/directories/{$key}")
                ->assertOk();
        }
    }

    public function test_users_api_crud_smoke_flow(): void
    {
        $viewerRoleId = (int) Role::query()->where('name', 'viewer')->value('id');

        $created = $this->actingAs($this->admin)
            ->postJson('/api/admin/users', [
                'name' => 'Smoke User',
                'email' => 'smoke.user@example.com',
                'password' => 'password123',
                'role_ids' => [$viewerRoleId],
            ]);

        $created->assertCreated();
        $userId = (int) $created->json('user.id');
        $this->assertGreaterThan(0, $userId);

        $this->actingAs($this->admin)
            ->putJson("/api/admin/users/{$userId}", [
                'name' => 'Smoke User Updated',
                'email' => 'smoke.user.updated@example.com',
                'role_ids' => [$viewerRoleId],
            ])
            ->assertOk()
            ->assertJsonPath('user.email', 'smoke.user.updated@example.com');

        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/users/{$userId}")
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $userId]);
    }

    public function test_roles_api_crud_smoke_flow(): void
    {
        $permissionId = (int) Permission::query()->where('name', 'directories.view')->value('id');

        $created = $this->actingAs($this->admin)
            ->postJson('/api/admin/roles', [
                'name' => 'smoke-role',
                'permission_ids' => [$permissionId],
            ]);

        $created->assertCreated();
        $roleId = (int) $created->json('role.id');
        $this->assertGreaterThan(0, $roleId);

        $this->actingAs($this->admin)
            ->putJson("/api/admin/roles/{$roleId}", [
                'name' => 'smoke-role-updated',
                'permission_ids' => [$permissionId],
            ])
            ->assertOk()
            ->assertJsonPath('role.name', 'smoke-role-updated');

        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/roles/{$roleId}")
            ->assertOk();

        $this->assertDatabaseMissing('roles', ['id' => $roleId]);
    }
}
