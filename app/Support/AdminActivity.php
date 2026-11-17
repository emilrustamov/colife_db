<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class AdminActivity
{
    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public static function log(
        string $event,
        string $subjectType,
        int|string|null $subjectId,
        ?User $actor,
        ?array $old = null,
        ?array $new = null,
    ): void {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        $now = now();

        DB::table('activity_logs')->insert([
            'id' => (string) Str::uuid(),
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'user_id' => $actor?->id,
            'old_values' => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'new_values' => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'happened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function roleSnapshot(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
        ];
    }

    public static function userSnapshot(User $user): array
    {
        $user->loadMissing('roles:id,name');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->sort()->values()->all(),
        ];
    }
}
