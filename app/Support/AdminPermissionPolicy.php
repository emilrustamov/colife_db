<?php

namespace App\Support;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminPermissionPolicy
{
    /**
     * When a permission is granted, also grant these.
     *
     * @return array<string, list<string>>
     */
    public static function companions(): array
    {
        return [
            'users.manage' => ['directory.users'],
            'users.edit' => ['directory.users'],
            'users.delete' => ['directory.users'],
            'roles.manage' => ['directory.roles'],
            'ol-bot.templates.edit' => ['ol-bot.templates'],
        ];
    }

    /**
     * @param  list<string>|\Illuminate\Support\Collection<int, string>  $permissionNames
     * @return list<string>
     */
    public static function expand(iterable $permissionNames): array
    {
        $set = [];
        foreach ($permissionNames as $name) {
            $name = (string) $name;
            if ($name === '') {
                continue;
            }
            $set[$name] = true;
            foreach (self::companions()[$name] ?? [] as $companion) {
                $set[$companion] = true;
            }
        }

        return array_keys($set);
    }

    /**
     * @param  list<int|string>  $permissionIds
     * @return list<string>
     */
    public static function expandIds(array $permissionIds): array
    {
        if ($permissionIds === []) {
            return [];
        }

        $names = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('id', $permissionIds)
            ->pluck('name')
            ->all();

        return self::expand($names);
    }

    /**
     * Prevent locking yourself out when editing your own role.
     * Users have a single role, so the edited role's permissions become the actor's permissions.
     *
     * @param  list<string>  $permissionNames
     */
    public static function assertRoleUpdateSafe(?User $actor, Role $role, array $permissionNames): void
    {
        if ($role->name === 'admin' && ! in_array('roles.manage', $permissionNames, true)) {
            throw new HttpException(403, 'Admin role must keep roles.manage.');
        }

        if ($actor === null || ! $actor->hasRole($role->name)) {
            return;
        }

        if ($actor->can('roles.manage') && ! in_array('roles.manage', $permissionNames, true)) {
            throw new HttpException(403, 'You cannot remove your own roles.manage access.');
        }
    }

    public static function assertRoleDeletable(Role $role): void
    {
        if ($role->name === 'admin') {
            throw new HttpException(403, 'Admin role cannot be deleted.');
        }

        if (! $role->hasPermissionTo('roles.manage')) {
            return;
        }

        $remainingManagers = User::query()
            ->permission('roles.manage')
            ->whereDoesntHave('roles', static function ($query) use ($role): void {
                $query->where('roles.id', $role->id);
            })
            ->count();

        if ($remainingManagers === 0) {
            throw new HttpException(403, 'Cannot delete the last role with roles.manage.');
        }
    }

    public static function assertUserRoleAssignmentSafe(?User $actor, User $target, Role $newRole): void
    {
        if ($actor === null || $actor->id !== $target->id) {
            return;
        }

        if ($actor->can('roles.manage') && ! $newRole->hasPermissionTo('roles.manage')) {
            throw new HttpException(403, 'You cannot assign yourself a role without roles.manage.');
        }
    }

    public static function assertUserDeletable(?User $actor, User $target): void
    {
        if ($actor !== null && $actor->id === $target->id) {
            throw new HttpException(403, 'You cannot delete yourself.');
        }

        if (! $target->can('roles.manage')) {
            return;
        }

        $others = User::query()
            ->permission('roles.manage')
            ->where('id', '!=', $target->id)
            ->count();

        if ($others === 0) {
            throw new HttpException(403, 'Cannot delete the last user with roles.manage.');
        }
    }
}
