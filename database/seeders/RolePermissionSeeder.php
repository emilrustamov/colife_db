<?php

namespace Database\Seeders;

use App\Http\Controllers\DirectoryController;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (
            !Schema::hasTable('permissions') ||
            !Schema::hasTable('roles') ||
            !Schema::hasTable('model_has_roles') ||
            !Schema::hasTable('role_has_permissions')
        ) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissionNames = ['directories.view', 'users.manage', 'roles.manage'];

        foreach ($permissionNames as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (array_keys(DirectoryController::definitions()) as $directoryKey) {
            Permission::firstOrCreate([
                'name' => 'directory.'.$directoryKey,
                'guard_name' => 'web',
            ]);
        }

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $viewerRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);

        $adminRole->syncPermissions(Permission::query()->where('guard_name', 'web')->pluck('name')->all());
        $viewerRole->syncPermissions(Permission::query()->where('name', 'directories.view')->pluck('name')->all());

        $user = User::query()->first();

        if ($user !== null && !$user->hasRole('admin')) {
            $user->assignRole('admin');
        }
    }
}
