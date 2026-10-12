<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Base data every installation needs. Idempotent. Institutional data
 * (departments, authorities, holidays, workflows) is entered by
 * administrators; DemoDataSeeder provides a synthetic set for development.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            CalendarSeeder::class,
            NotificationTemplateSeeder::class,
            AdminSeeder::class,
        ]);
    }
}
