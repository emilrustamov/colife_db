<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleAdminController extends Controller
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
            ->get(['id', 'name']);

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

        $role = Role::query()->create([
            'name' => $validated['name'],
            'guard_name' => 'web',
        ]);

        $permissionIds = $validated['permission_ids'] ?? [];
        $role->syncPermissions(Permission::query()->whereIn('id', $permissionIds)->where('guard_name', 'web')->pluck('name')->all());

        $role->load('permissions:id,name');

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

        $role->name = $validated['name'];
        $role->save();

        $permissionIds = $validated['permission_ids'] ?? [];
        $role->syncPermissions(Permission::query()->whereIn('id', $permissionIds)->where('guard_name', 'web')->pluck('name')->all());

        $role->load('permissions:id,name');

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
    public function destroy(Role $role): JsonResponse
    {
        abort_unless($role->guard_name === 'web', 404);

        if ($role->name === 'admin') {
            abort(403);
        }

        $role->delete();

        return response()->json(['ok' => true]);
    }
}
