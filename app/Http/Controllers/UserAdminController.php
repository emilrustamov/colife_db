<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserAdminController extends Controller
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
     * Create a new user and assign roles.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        $roleIds = $validated['role_ids'] ?? [];

        if ($roleIds !== []) {
            $user->syncRoles(Role::query()->whereIn('id', $roleIds)->where('guard_name', 'web')->pluck('name')->all());
        }

        $user->load('roles:id,name');

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
     * Update user fields and roles.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];

        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();

        $roleIds = $validated['role_ids'] ?? [];
        $user->syncRoles(Role::query()->whereIn('id', $roleIds)->where('guard_name', 'web')->pluck('name')->all());

        $user->load('roles:id,name');

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
     * Delete a user (not yourself).
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            abort(403);
        }

        $user->delete();

        return response()->json(['ok' => true]);
    }
}
