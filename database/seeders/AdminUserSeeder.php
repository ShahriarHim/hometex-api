<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Creates the default admin user and assigns the 'admin' Spatie role.
 * Credentials come from env — never hardcoded.
 *
 * Run: php artisan db:seed --class=AdminUserSeeder
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_SEED_EMAIL', 'admin@hometexbangladesh.org');
        $password = env('ADMIN_SEED_PASSWORD');

        if (! $password) {
            $this->command->error('ADMIN_SEED_PASSWORD is not set in .env — skipping admin seeder.');
            return;
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'uuid'       => (string) Str::uuid(),
                'first_name' => 'System',
                'last_name'  => 'Admin',
                'password'   => Hash::make($password),
                'user_type'  => 'admin',
                'status'     => 'active',
            ]
        );

        // Ensure user_type is admin even if user already existed
        if ($user->user_type !== 'admin') {
            $user->update(['user_type' => 'admin']);
        }

        $user->syncRoles([Role::findByName('admin', 'sanctum')]);

        $this->command->info("Admin user ready: {$email}");
    }
}
