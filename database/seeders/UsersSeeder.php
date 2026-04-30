<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class UsersSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's users.
     */
    public function run(): void
    {
        $email = 'test@example.com';
        $plainPassword = 'password';

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Test User',
                'password' => Hash::make($plainPassword),
                'is_superadmin' => true,
            ]
        );

        if (! $user->is_superadmin) {
            $user->is_superadmin = true;
            $user->save();
        }

        if (! Hash::check($plainPassword, (string) $user->password)) {
            $user->password = Hash::make($plainPassword);
            $user->save();
        }

        if (Schema::hasTable('roles') && Schema::hasTable('model_has_roles')) {
            if (!$user->hasRole('admin')) {
                $user->assignRole('admin');
            }
        }
    }
}

