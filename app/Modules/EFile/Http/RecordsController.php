<?php

namespace App\Modules\EFile\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\EFile\Models\EFile;
use App\Modules\EFile\Models\FileCategory;
use App\Modules\EFile\Models\RetentionSchedule;
use App\Modules\EFile\Services\EFileService;
use App\Modules\Identity\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Records management: file categories, retention schedules, disposition (FILE-005). */
class RecordsController extends Controller
{
    public function __construct(private EFileService $files, private AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize(Permissions::EFILES_RECORDS);

        return Inertia::render('Records/Index', [
            'categories' => FileCategory::query()->with('retention:id,name')->orderBy('name')->get()
                ->map(fn ($c) => [...$c->only('id', 'code', 'name', 'description', 'numbering_pattern', 'default_classification', 'retention_schedule_id', 'is_active'), 'retention' => $c->retention?->name]),
            'schedules' => RetentionSchedule::query()->orderBy('name')->get(),
            'due' => EFile::query()->where('status', 'closed')->where(fn ($q) => $q->where('disposition', 'due')->orWhere('legal_hold', true))
                ->with('retention:id,code,action', 'department:id,code')->orderBy('retain_until')->limit(200)->get()
                ->map(fn ($f) => [
                    ...$f->only('id', 'file_number', 'classification', 'disposition', 'legal_hold'),
                    'subject' => $f->isSensitive() ? '(classified)' : $f->subject,
                    'department' => $f->department->code, 'retain_until' => $f->retain_until?->toDateString(),
                    'schedule' => $f->retention?->code, 'suggested' => $f->retention?->action,
                ]),
            'classifications' => EFile::CLASSIFICATIONS,
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::EFILES_RECORDS);
        $c = FileCategory::create($this->category($request));
        $this->audit->record('records.category.created', $c, $c->only('code', 'numbering_pattern'));

        return back()->with('success', 'Category created.');
    }

    public function updateCategory(Request $request, FileCategory $category): RedirectResponse
    {
        $this->authorize(Permissions::EFILES_RECORDS);
        $category->fill($this->category($request, $category));
        $changes = $category->getDirty();
        $category->save();
        $this->audit->record('records.category.updated', $category, ['changes' => $changes]);

        return back()->with('success', 'Category updated. Existing file numbers are unchanged.');
    }

    public function storeSchedule(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::EFILES_RECORDS);
        $s = RetentionSchedule::create($this->schedule($request));
        $this->audit->record('records.schedule.created', $s, $s->only('code', 'retain_years', 'action'));

        return back()->with('success', 'Retention schedule created.');
    }

    public function updateSchedule(Request $request, RetentionSchedule $schedule): RedirectResponse
    {
        $this->authorize(Permissions::EFILES_RECORDS);
        $schedule->fill($this->schedule($request, $schedule));
        $changes = $schedule->getDirty();
        $schedule->save();
        $this->audit->record('records.schedule.updated', $schedule, ['changes' => $changes]);

        return back()->with('success', 'Retention schedule updated. It applies to files closed from now on.');
    }

    public function dispose(Request $request, EFile $efile): RedirectResponse
    {
        $this->authorize(Permissions::EFILES_RECORDS);
        $data = $request->validate(['decision' => ['required', Rule::in(['destroy', 'transfer', 'retain'])], 'reason' => ['required', 'string', 'max:2000'], 'extend_years' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $this->files->dispose($efile, $request->user(), $data['decision'], $data['reason'], $data['extend_years'] ?? null);

        return back()->with('success', 'Disposition recorded.');
    }

    public function legalHold(Request $request, EFile $efile): RedirectResponse
    {
        $this->authorize(Permissions::EFILES_RECORDS);
        $data = $request->validate(['hold' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->files->setLegalHold($efile, $request->user(), $data['hold'], $data['reason']);

        return back()->with('success', $data['hold'] ? 'Legal hold placed.' : 'Legal hold lifted.');
    }

    private function category(Request $request, ?FileCategory $c = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('file_categories')->ignore($c)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'numbering_pattern' => ['required', 'string', 'max:100', 'regex:/\{SEQ(:\d+)?\}/'],
            'default_classification' => ['required', Rule::in(EFile::CLASSIFICATIONS)],
            'retention_schedule_id' => ['nullable', 'exists:retention_schedules,id'],
            'is_active' => ['boolean'],
        ]);
    }

    private function schedule(Request $request, ?RetentionSchedule $s = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('retention_schedules')->ignore($s)],
            'name' => ['required', 'string', 'max:255'],
            'retain_years' => ['nullable', 'integer', 'min:0', 'max:100'],
            'action' => ['required', Rule::in(RetentionSchedule::ACTIONS)],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
