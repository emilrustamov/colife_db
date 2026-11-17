<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPermissionsTest extends TestCase
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

    public function test_viewer_has_narrow_catalog_access_and_no_ol(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');

        $this->actingAs($viewer)->get('/directories/contacts')->assertOk();
        $this->actingAs($viewer)->get('/directories/apartments')->assertOk();
        $this->actingAs($viewer)->get('/directories/buildings')->assertForbidden();
        $this->actingAs($viewer)->get('/ol-bot/analytics')->assertForbidden();
        $this->actingAs($viewer)->get('/ol-bot/templates')->assertForbidden();
    }

    public function test_users_edit_without_delete_cannot_delete(): void
    {
        $role = Role::query()->create(['name' => 'user-editor', 'guard_name' => 'web']);
        $role->syncPermissions([
            'directory.users',
            'users.edit',
        ]);

        $editor = User::factory()->create();
        $editor->assignRole('user-editor');

        $target = User::factory()->create();
        $target->assignRole('viewer');

        $this->actingAs($editor)
            ->putJson("/api/admin/users/{$target->id}", [
                'name' => 'Edited',
                'email' => $target->email,
                'role_id' => (int) Role::query()->where('name', 'viewer')->value('id'),
            ])
            ->assertOk();

        $this->actingAs($editor)
            ->deleteJson("/api/admin/users/{$target->id}")
            ->assertForbidden();
    }

    public function test_templates_view_without_edit_cannot_post(): void
    {
        $role = Role::query()->create(['name' => 'ol-viewer', 'guard_name' => 'web']);
        $role->syncPermissions(['ol-bot.templates']);

        $user = User::factory()->create();
        $user->assignRole('ol-viewer');

        $this->actingAs($user)->get('/ol-bot/templates/main')->assertOk();
        $this->actingAs($user)
            ->post('/ol-bot/templates', [
                'template_key' => 'main',
                'kind' => 'instruction',
                'body' => 'x',
                'note' => '',
                'scope' => 'main',
            ])
            ->assertForbidden();
    }

    public function test_saving_edit_permission_auto_adds_view_companion(): void
    {
        $editId = (int) Permission::query()->where('name', 'ol-bot.templates.edit')->value('id');

        $created = $this->actingAs($this->admin)
            ->postJson('/api/admin/roles', [
                'name' => 'ol-editor',
                'permission_ids' => [$editId],
            ])
            ->assertCreated();

        $names = collect($created->json('role.permissions'))->pluck('name');
        $this->assertTrue($names->contains('ol-bot.templates.edit'));
        $this->assertTrue($names->contains('ol-bot.templates'));
    }

    public function test_cannot_strip_critical_permissions_from_admin_role(): void
    {
        $adminRole = Role::query()->where('name', 'admin')->firstOrFail();
        $contactsId = (int) Permission::query()->where('name', 'directory.contacts')->value('id');

        $this->actingAs($this->admin)
            ->putJson("/api/admin/roles/{$adminRole->id}", [
                'name' => 'admin',
                'permission_ids' => [$contactsId],
            ])
            ->assertForbidden();
    }

    public function test_cannot_assign_self_role_without_roles_manage(): void
    {
        $viewerRoleId = (int) Role::query()->where('name', 'viewer')->value('id');

        $this->actingAs($this->admin)
            ->putJson("/api/admin/users/{$this->admin->id}", [
                'name' => $this->admin->name,
                'email' => $this->admin->email,
                'role_id' => $viewerRoleId,
            ])
            ->assertForbidden();
    }

    public function test_user_and_role_changes_are_audited(): void
    {
        $viewerRoleId = (int) Role::query()->where('name', 'viewer')->value('id');

        $created = $this->actingAs($this->admin)
            ->postJson('/api/admin/users', [
                'name' => 'Audit User',
                'email' => 'audit.user@example.com',
                'password' => 'password123',
                'role_id' => $viewerRoleId,
            ])
            ->assertCreated();

        $userId = (int) $created->json('user.id');

        $this->assertDatabaseHas('activity_logs', [
            'event' => 'admin.user.created',
            'subject_type' => User::class,
            'subject_id' => (string) $userId,
        ]);

        $permissionId = (int) Permission::query()->where('name', 'directory.contacts')->value('id');
        $roleCreated = $this->actingAs($this->admin)
            ->postJson('/api/admin/roles', [
                'name' => 'audit-role',
                'permission_ids' => [$permissionId],
            ])
            ->assertCreated();

        $roleId = (int) $roleCreated->json('role.id');

        $this->assertTrue(
            DB::table('activity_logs')->where('event', 'admin.role.created')->where('subject_id', (string) $roleId)->exists()
        );
    }
}
