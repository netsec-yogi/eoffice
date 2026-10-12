<?php

namespace Database\Seeders;

use App\Modules\Calendar\Models\BusinessCalendar;
use Illuminate\Database\Seeder;

/**
 * A starting calendar so SLAs have working time to count. The values are a
 * placeholder (ASSUMPTIONS A-06); the Registrar's office sets the real
 * working hours and holiday list in Admin -> Calendars.
 */
class CalendarSeeder extends Seeder
{
    public function run(): void
    {
        BusinessCalendar::query()->firstOrCreate(['code' => 'INSTITUTE'], [
            'name' => 'Institute working calendar (placeholder - confirm hours and holidays)',
            'working_days' => [1, 2, 3, 4, 5],
            'day_starts_at' => '09:00',
            'day_ends_at' => '17:30',
            'is_default' => true,
        ]);
    }
}
