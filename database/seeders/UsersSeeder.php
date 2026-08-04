<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class UsersSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's users.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $email = 'test@example.com';
        $plainPassword = (string) env('SEED_ADMIN_PASSWORD', '');
        if ($plainPassword === '') {
            $plainPassword = Str::password(32);
            $this->command?->warn('SEED_ADMIN_PASSWORD not set; generated a one-time password for '.$email);
            $this->command?->warn($plainPassword);
        }

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Test User',
                'password' => Hash::make($plainPassword),
                'is_superadmin' => false,
            ]
        );

        if (Schema::hasTable('roles') && Schema::hasTable('model_has_roles')) {
            if (! $user->hasRole('admin')) {
                $user->assignRole('admin');
            }
        }
    }
}
