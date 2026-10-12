<?php

namespace Tests\Feature\Platform;

use App\Modules\Calendar\Models\BusinessCalendar;
use App\Modules\Calendar\Services\BusinessTime;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** CAL-001 */
class BusinessTimeTest extends TestCase
{
    private BusinessTime $time;

    protected function setUp(): void
    {
        parent::setUp();
        $calendar = BusinessCalendar::create(['code' => 'T', 'name' => 'Test', 'working_days' => [1, 2, 3, 4, 5], 'day_starts_at' => '09:00', 'day_ends_at' => '17:00', 'is_default' => true]);
        $calendar->holidays()->create(['date' => '2026-10-02', 'name' => 'Gandhi Jayanti']); // a Friday
        $this->time = app(BusinessTime::class);
    }

    public function test_working_days_skip_weekends_and_holidays(): void
    {
        // Thursday 1 Oct + 2 working days: Fri 2 is a holiday, Sat/Sun skipped -> Tue 6 Oct, end of day.
        $due = $this->time->add(CarbonImmutable::parse('2026-10-01 11:00'), 2, 'working_days');
        $this->assertSame('2026-10-06 17:00', $due->format('Y-m-d H:i'));
    }

    public function test_working_hours_roll_over_nights_weekends_and_holidays(): void
    {
        // Thursday 15:00 + 4 working hours: 2h Thursday, Friday holiday, weekend, 2h Monday -> Mon 11:00.
        $due = $this->time->add(CarbonImmutable::parse('2026-10-01 15:00'), 4, 'working_hours');
        $this->assertSame('2026-10-05 11:00', $due->format('Y-m-d H:i'));

        // Starting before office hours counts from opening.
        $due = $this->time->add(CarbonImmutable::parse('2026-10-05 07:30'), 1.5, 'working_hours');
        $this->assertSame('2026-10-05 10:30', $due->format('Y-m-d H:i'));
    }

    public function test_calendar_units_ignore_the_calendar(): void
    {
        $this->assertSame('2026-10-03 11:00', $this->time->add(CarbonImmutable::parse('2026-10-01 11:00'), 2, 'days')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-01 14:00', $this->time->add(CarbonImmutable::parse('2026-10-01 11:00'), 3, 'hours')->format('Y-m-d H:i'));
    }
}
