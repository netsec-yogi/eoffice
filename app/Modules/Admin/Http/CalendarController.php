<?php

namespace App\Modules\Admin\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Calendar\Models\BusinessCalendar;
use App\Modules\Calendar\Models\Holiday;
use App\Modules\Identity\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Working calendars and holidays used for SLA deadlines (CAL-001). */
class CalendarController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permissions::CALENDARS_MANAGE);
        $year = (int) $request->integer('year', (int) now()->format('Y'));

        return Inertia::render('Admin/Calendars/Index', [
            'year' => $year,
            'calendars' => BusinessCalendar::query()->orderByDesc('is_default')->orderBy('name')
                ->with(['holidays' => fn ($q) => $q->whereYear('date', $year)->orderBy('date')])
                ->get()
                ->map(fn (BusinessCalendar $c) => [
                    ...$c->only('id', 'code', 'name', 'working_days', 'is_default'),
                    'day_starts_at' => substr((string) $c->day_starts_at, 0, 5),
                    'day_ends_at' => substr((string) $c->day_ends_at, 0, 5),
                    'holidays' => $c->holidays->map(fn (Holiday $h) => ['id' => $h->id, 'date' => $h->date->toDateString(), 'name' => $h->name, 'kind' => $h->kind]),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::CALENDARS_MANAGE);
        $calendar = DB::transaction(function () use ($request) {
            $data = $this->validated($request);
            if ($data['is_default'] ?? false) {
                BusinessCalendar::query()->update(['is_default' => false]);
            }

            return BusinessCalendar::create($data);
        });
        $this->audit->record('admin.calendar.created', $calendar, $calendar->only('code', 'working_days', 'day_starts_at', 'day_ends_at', 'is_default'));

        return back()->with('success', 'Calendar created.');
    }

    public function update(Request $request, BusinessCalendar $calendar): RedirectResponse
    {
        $this->authorize(Permissions::CALENDARS_MANAGE);
        DB::transaction(function () use ($request, $calendar) {
            $data = $this->validated($request, $calendar);
            if ($data['is_default'] ?? false) {
                BusinessCalendar::query()->whereKeyNot($calendar->id)->update(['is_default' => false]);
            }
            $calendar->fill($data);
            $changes = $calendar->getDirty();
            $calendar->save();
            $this->audit->record('admin.calendar.updated', $calendar, ['changes' => $changes]);
        });

        return back()->with('success', 'Calendar updated.');
    }

    public function storeHoliday(Request $request, BusinessCalendar $calendar): RedirectResponse
    {
        $this->authorize(Permissions::CALENDARS_MANAGE);
        $data = $request->validate([
            'date' => ['required', 'date', Rule::unique('holidays')->where('business_calendar_id', $calendar->id)],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(['gazetted', 'restricted', 'local'])],
        ]);
        $holiday = $calendar->holidays()->create($data);
        $this->audit->record('admin.calendar.holiday_added', $calendar, $data);

        return back()->with('success', 'Holiday added.');
    }

    public function destroyHoliday(Holiday $holiday): RedirectResponse
    {
        $this->authorize(Permissions::CALENDARS_MANAGE);
        $holiday->delete();
        $this->audit->record('admin.calendar.holiday_removed', $holiday->calendar, ['date' => $holiday->date->toDateString(), 'name' => $holiday->name]);

        return back()->with('success', 'Holiday removed.');
    }

    private function validated(Request $request, ?BusinessCalendar $calendar = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('business_calendars')->ignore($calendar)],
            'name' => ['required', 'string', 'max:255'],
            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => ['integer', 'between:1,7', 'distinct'],
            'day_starts_at' => ['required', 'date_format:H:i'],
            'day_ends_at' => ['required', 'date_format:H:i', 'after:day_starts_at'],
            'is_default' => ['boolean'],
        ]);
    }
}
