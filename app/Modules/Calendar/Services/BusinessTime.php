<?php

namespace App\Modules\Calendar\Services;

use App\Modules\Calendar\Models\BusinessCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Deadline arithmetic on a business calendar: working days, working hours
 * and holidays (CAL-001). With no calendar configured every day and hour
 * counts, and SLA screens say so (no silent assumptions about working time).
 */
class BusinessTime
{
    /** @var array<string, array{days: list<int>, start: string, end: string, holidays: array<string, true>}|null> */
    private array $loaded = [];

    public const UNITS = ['minutes', 'hours', 'days', 'working_hours', 'working_days'];

    public function add(CarbonInterface $from, int|float $amount, string $unit, ?string $calendar = null): CarbonImmutable
    {
        $from = CarbonImmutable::instance($from);

        return match ($unit) {
            'minutes' => $from->addMinutes((int) round($amount)),
            'hours' => $from->addMinutes((int) round($amount * 60)),
            'days' => $from->addDays((int) $amount),
            'working_hours' => $this->addWorkingMinutes($from, (int) round($amount * 60), $calendar),
            'working_days' => $this->addWorkingDays($from, (int) $amount, $calendar),
            default => throw new InvalidArgumentException("Unknown duration unit [{$unit}]."),
        };
    }

    public function isWorkingDay(CarbonInterface $date, ?string $calendar = null): bool
    {
        $cal = $this->calendar($calendar);
        if ($cal === null) {
            return true;
        }

        return in_array($date->isoWeekday(), $cal['days'], true) && ! isset($cal['holidays'][$date->toDateString()]);
    }

    /**
     * The end of the n-th working day after $from. A request that arrives
     * on a working day does not count that day.
     */
    public function addWorkingDays(CarbonInterface $from, int $days, ?string $calendar = null): CarbonImmutable
    {
        $cal = $this->calendar($calendar);
        $date = CarbonImmutable::instance($from);
        if ($cal === null) {
            return $date->addDays($days);
        }

        $counted = 0;
        $guard = 0;
        while ($counted < $days) {
            $date = $date->addDay();
            if ($this->isWorkingDay($date, $calendar)) {
                $counted++;
            }
            if (++$guard > 3660) {
                throw new InvalidArgumentException('Calendar has no working days.');
            }
        }

        return $date->setTimeFromTimeString($cal['end']);
    }

    public function addWorkingMinutes(CarbonInterface $from, int $minutes, ?string $calendar = null): CarbonImmutable
    {
        $cal = $this->calendar($calendar);
        $cursor = CarbonImmutable::instance($from);
        if ($cal === null) {
            return $cursor->addMinutes($minutes);
        }

        $remaining = $minutes;
        $guard = 0;
        while (true) {
            if (++$guard > 3660) {
                throw new InvalidArgumentException('Calendar has no working time.');
            }
            if (! $this->isWorkingDay($cursor, $calendar)) {
                $cursor = $cursor->addDay()->setTimeFromTimeString($cal['start']);

                continue;
            }
            $dayStart = $cursor->setTimeFromTimeString($cal['start']);
            $dayEnd = $cursor->setTimeFromTimeString($cal['end']);
            if ($cursor->lt($dayStart)) {
                $cursor = $dayStart;
            }
            if ($cursor->gte($dayEnd)) {
                $cursor = $cursor->addDay()->setTimeFromTimeString($cal['start']);

                continue;
            }
            $available = (int) $cursor->diffInMinutes($dayEnd);
            if ($remaining <= $available) {
                return $cursor->addMinutes($remaining);
            }
            $remaining -= $available;
            $cursor = $cursor->addDay()->setTimeFromTimeString($cal['start']);
        }
    }

    public function hasCalendar(?string $calendar = null): bool
    {
        return $this->calendar($calendar) !== null;
    }

    /** Forget cached calendars (after an administrator edits one). */
    public function flush(): void
    {
        $this->loaded = [];
    }

    private function calendar(?string $code): ?array
    {
        $key = $code ?? '__default';
        if (array_key_exists($key, $this->loaded)) {
            return $this->loaded[$key];
        }

        $model = BusinessCalendar::query()
            ->when($code, fn ($q) => $q->where('code', $code), fn ($q) => $q->where('is_default', true))
            ->with('holidays:id,business_calendar_id,date')
            ->first();

        return $this->loaded[$key] = $model ? [
            'days' => array_map('intval', $model->working_days),
            'start' => substr((string) $model->day_starts_at, 0, 5),
            'end' => substr((string) $model->day_ends_at, 0, 5),
            'holidays' => $model->holidays->mapWithKeys(fn ($h) => [$h->date->toDateString() => true])->all(),
        ] : null;
    }
}
