<?php

namespace App\Http\Controllers;

use App\Support\AdminActivity;
use App\Support\AdminPermissionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleAdmin extends Controller
{
    /**
     * List roles with permissions and all permission options.
     */
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get()
            ->map(static function (Role $role): array {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'permissions' => $role->permissions->map(static fn (Permission $p): array => [
                        'id' => $p->id,
                        'name' => $p->name,
                    ])->all(),
                ];
            });

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Permission $permission): array => $this->permissionPayload($permission))
            ->sortBy(static function (array $permission): string {
                $groupOrder = match ($permission['group']) {
                    'admin' => '0',
                    'catalog' => '1',
                    'ol-bot' => '2',
                    default => '9',
                };

                return $groupOrder.'|'.$permission['label'].'|'.$permission['name'];
            })
            ->values()
            ->all();

        return response()->json([
            'roles' => $roles,
            'permissions' => $permissions,
        ]);
    }

    /**
     * Create a role with permissions.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->where('guard_name', 'web')],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $permissionNames = AdminPermissionPolicy::expandIds($validated['permission_ids'] ?? []);

        $role = Role::query()->create([
            'name' => $validated['name'],
            'guard_name' => 'web',
        ]);

        $role->syncPermissions($permissionNames);
        $role->load('permissions:id,name');

        AdminActivity::log(
            'admin.role.created',
            Role::class,
            $role->id,
            $request->user(),
            null,
            AdminActivity::roleSnapshot($role),
        );

        return response()->json([
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->map(static fn (Permission $p): array => [
                    'id' => $p->id,
                    'name' => $p->name,
                ])->all(),
            ],
        ], 201);
    }

    /**
     * Update role name and permissions.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        abort_unless($role->guard_name === 'web', 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role->id)],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        if ($role->name === 'admin' && $validated['name'] !== 'admin') {
            abort(403);
        }

        $role->load('permissions:id,name');
        $before = AdminActivity::roleSnapshot($role);

        $permissionNames = AdminPermissionPolicy::expandIds($validated['permission_ids'] ?? []);
        AdminPermissionPolicy::assertRoleUpdateSafe($request->user(), $role, $permissionNames);

        $role->name = $validated['name'];
        $role->save();
        $role->syncPermissions($permissionNames);
        $role->load('permissions:id,name');

        AdminActivity::log(
            'admin.role.updated',
            Role::class,
            $role->id,
            $request->user(),
            $before,
            AdminActivity::roleSnapshot($role),
        );

        return response()->json([
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->map(static fn (Permission $p): array => [
                    'id' => $p->id,
                    'name' => $p->name,
                ])->all(),
            ],
        ]);
    }

    /**
     * Delete a role unless it is the system admin role.
     */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        abort_unless($role->guard_name === 'web', 404);

        $role->load('permissions:id,name');
        AdminPermissionPolicy::assertRoleDeletable($role);

        $before = AdminActivity::roleSnapshot($role);
        $roleId = $role->id;
        $role->delete();

        AdminActivity::log(
            'admin.role.deleted',
            Role::class,
            $roleId,
            $request->user(),
            $before,
            null,
        );

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{id: int, name: string, label: string, group: string, directory_key: string|null}
     */
    private function permissionPayload(Permission $permission): array
    {
        $name = $permission->name;
        $directoryKey = null;
        $group = 'admin';
        $label = $name;

        if (str_starts_with($name, 'directory.')) {
            $directoryKey = substr($name, strlen('directory.'));
            $group = 'catalog';
            $definitions = DirectoryController::definitions();
            $title = isset($definitions[$directoryKey]['title'])
                ? (string) $definitions[$directoryKey]['title']
                : $directoryKey;
            $label = match ($directoryKey) {
                'users' => 'View users list',
                'roles' => 'View roles list',
                default => 'View: '.$title,
            };
        } elseif (str_starts_with($name, 'ol-bot.')) {
            $group = 'ol-bot';
            $label = match ($name) {
                'ol-bot.analytics' => 'OL Analytics (chats & stats)',
                'ol-bot.catalog' => 'OL Catalog (tab on analytics page)',
                'ol-bot.templates' => 'OL Templates (view)',
                'ol-bot.templates.edit' => 'OL Templates (edit)',
                default => $name,
            };
        } else {
            $label = match ($name) {
                'users.manage' => 'Create users',
                'users.edit' => 'Edit users',
                'users.delete' => 'Delete users',
                'roles.manage' => 'Manage roles',
                default => $name,
            };
        }

        return [
            'id' => $permission->id,
            'name' => $name,
            'label' => $label,
            'group' => $group,
            'directory_key' => $directoryKey,
        ];
    }
}
