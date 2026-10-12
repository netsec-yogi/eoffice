<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;

/**
 * Bootstrap the first system administrator: a directory account named in
 * SEED_ADMIN_USERNAME. Only the username is stored here; name, e-mail and
 * the rest arrive from the directory on first sign-in. No password exists.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $username = strtolower(trim((string) env('SEED_ADMIN_USERNAME')));
        if ($username === '') {
            $this->command?->warn('SEED_ADMIN_USERNAME not set: no administrator seeded.');

            return;
        }

        $user = User::query()->where('username', $username)->first();
        if (! $user) {
            $user = (new User)->forceFill(['username' => $username, 'name' => $username, 'auth_source' => 'ldap', 'category' => 'staff']);
            $user->save();
        }
        $user->assignRole('system_admin');
        $this->command?->info("System administrator: {$username} (signs in with directory credentials).");
    }
}
