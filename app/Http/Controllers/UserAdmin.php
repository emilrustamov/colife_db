<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AdminActivity;
use App\Support\AdminPermissionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserAdmin extends Controller
{
    /**
     * List users with roles and role options for assignment.
     */
    public function index(): JsonResponse
    {
        $users = User::query()
            ->with('roles:id,name')
            ->orderBy('name')
            ->get()
            ->map(static function (User $user): array {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->map(static fn (Role $role): array => [
                        'id' => $role->id,
                        'name' => $role->name,
                    ])->all(),
                ];
            });

        $roles = Role::query()->where('guard_name', 'web')->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'users' => $users,
            'roles' => $roles,
        ]);
    }

    /**
     * Create a new user and assign a single role.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
        ]);

        $role = Role::query()
            ->where('id', $validated['role_id'])
            ->where('guard_name', 'web')
            ->firstOrFail();

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        $user->syncRoles([$role->name]);
        $user->load('roles:id,name');

        AdminActivity::log(
            'admin.user.created',
            User::class,
            $user->id,
            $request->user(),
            null,
            AdminActivity::userSnapshot($user),
        );

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->map(static fn (Role $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                ])->all(),
            ],
        ], 201);
    }

    /**
     * Update user fields and the single assigned role.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
        ]);

        $role = Role::query()
            ->where('id', $validated['role_id'])
            ->where('guard_name', 'web')
            ->firstOrFail();

        AdminPermissionPolicy::assertUserRoleAssignmentSafe($request->user(), $user, $role);

        $user->load('roles:id,name');
        $before = AdminActivity::userSnapshot($user);

        $user->name = $validated['name'];
        $user->email = $validated['email'];

        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();
        $user->syncRoles([$role->name]);
        $user->load('roles:id,name');

        AdminActivity::log(
            'admin.user.updated',
            User::class,
            $user->id,
            $request->user(),
            $before,
            AdminActivity::userSnapshot($user),
        );

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->map(static fn (Role $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                ])->all(),
            ],
        ]);
    }

    /**
     * Delete a user (not yourself / not last admin).
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        AdminPermissionPolicy::assertUserDeletable($request->user(), $user);

        $user->load('roles:id,name');
        $before = AdminActivity::userSnapshot($user);
        $userId = $user->id;
        $user->delete();

        AdminActivity::log(
            'admin.user.deleted',
            User::class,
            $userId,
            $request->user(),
            $before,
            null,
        );

        return response()->json(['ok' => true]);
    }
}
