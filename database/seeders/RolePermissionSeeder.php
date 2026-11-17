<?php

namespace Database\Seeders;

use App\Http\Controllers\DirectoryController;
use App\Models\User;
use App\Support\AdminMenu;
use App\Support\AdminPermissionPolicy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Narrow default catalog access for the viewer role.
     *
     * @var list<string>
     */
    private const VIEWER_DIRECTORY_KEYS = [
        'contacts',
        'contact-phones',
        'contact-emails',
        'contact-types',
        'apartments',
        'units',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (
            ! Schema::hasTable('permissions') ||
            ! Schema::hasTable('roles') ||
            ! Schema::hasTable('model_has_roles') ||
            ! Schema::hasTable('role_has_permissions')
        ) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissionNames = [
            'users.manage',
            'users.edit',
            'users.delete',
            'roles.manage',
            ...AdminMenu::olBotPermissionNames(),
        ];

        foreach ($permissionNames as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (array_keys(DirectoryController::definitions()) as $directoryKey) {
            Permission::firstOrCreate([
                'name' => 'directory.'.$directoryKey,
                'guard_name' => 'web',
            ]);
        }

        // Drop leftover global catalog permission if an old DB still has it.
        Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'directories.view')
            ->delete();

        $this->backfillCompanions();

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $viewerRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);

        $adminRole->syncPermissions(Permission::query()->where('guard_name', 'web')->pluck('name')->all());
        $viewerRole->syncPermissions(array_map(
            static fn (string $key): string => 'directory.'.$key,
            self::VIEWER_DIRECTORY_KEYS,
        ));

        $user = User::query()->first();

        if ($user !== null && ! $user->hasRole('admin')) {
            $user->assignRole('admin');
        }
    }

    /**
     * Ensure existing roles get view companions for edit/manage permissions.
     */
    private function backfillCompanions(): void
    {
        $roles = Role::query()->where('guard_name', 'web')->with('permissions:id,name')->get();

        foreach ($roles as $role) {
            if ($role->name === 'admin' || $role->name === 'viewer') {
                continue;
            }

            $expanded = AdminPermissionPolicy::expand($role->permissions->pluck('name'));
            $role->syncPermissions($expanded);
        }
    }
}
