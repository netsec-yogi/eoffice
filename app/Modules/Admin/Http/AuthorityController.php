<?php

namespace App\Modules\Admin\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\Authority;
use App\Modules\Identity\Models\AuthorityAssignment;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Approval authorities and who holds them (IAM-003). */
class AuthorityController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize(Permissions::ORGANISATION_MANAGE);

        $authorities = Authority::query()->orderBy('name')->with([
            'assignments' => fn ($q) => $q->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', now()->toDateString()))
                ->with(['user:id,name,username', 'department:id,code'])->orderBy('starts_on'),
        ])->get();

        return Inertia::render('Admin/Authorities/Index', [
            'authorities' => $authorities->map(fn (Authority $a) => [
                ...$a->only('id', 'code', 'name', 'scope', 'description', 'is_active'),
                'holders' => $a->assignments->map(fn (AuthorityAssignment $h) => [
                    'id' => $h->id,
                    'user' => $h->user->only('id', 'name', 'username'),
                    'department' => $h->department?->code,
                    'starts_on' => $h->starts_on->toDateString(),
                    'ends_on' => $h->ends_on?->toDateString(),
                    'is_acting' => $h->is_acting,
                    'order_reference' => $h->order_reference,
                    'current' => $h->starts_on->lte(today()),
                ]),
            ]),
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::ORGANISATION_MANAGE);
        $authority = Authority::create($this->validated($request));
        $this->audit->record('admin.authority.created', $authority, $authority->only('code', 'name', 'scope'));

        return back()->with('success', 'Authority created.');
    }

    public function update(Request $request, Authority $authority): RedirectResponse
    {
        $this->authorize(Permissions::ORGANISATION_MANAGE);
        $authority->fill($this->validated($request, $authority));
        $changes = $authority->getDirty();
        $authority->save();
        $this->audit->record('admin.authority.updated', $authority, ['changes' => $changes]);

        return back()->with('success', 'Authority updated.');
    }

    public function assign(Request $request, Authority $authority): RedirectResponse
    {
        $this->authorize(Permissions::ORGANISATION_MANAGE);
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'department_id' => [Rule::requiredIf($authority->isDepartmentScoped()), 'nullable', 'exists:departments,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'is_acting' => ['boolean'],
            'order_reference' => ['nullable', 'string', 'max:255'],
        ]);
        if (! $authority->isDepartmentScoped()) {
            $data['department_id'] = null;
        }

        $assignment = $authority->assignments()->make($data);
        $assignment->forceFill(['assigned_by' => $request->user()->id])->save();
        $this->audit->record('admin.authority.assigned', $assignment, [
            'authority' => $authority->code, 'user_id' => $data['user_id'], 'department_id' => $data['department_id'],
            'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null, 'is_acting' => $data['is_acting'] ?? false,
        ]);

        return back()->with('success', 'Holder assigned.');
    }

    public function endAssignment(Request $request, AuthorityAssignment $assignment): RedirectResponse
    {
        $this->authorize(Permissions::ORGANISATION_MANAGE);
        $data = $request->validate(['ends_on' => ['required', 'date', 'after_or_equal:'.$assignment->starts_on->toDateString()]]);
        $assignment->update($data);
        $this->audit->record('admin.authority.assignment_ended', $assignment, $data);

        return back()->with('success', 'Assignment end date set.');
    }

    private function validated(Request $request, ?Authority $authority = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_]+$/', Rule::unique('authorities')->ignore($authority)],
            'name' => ['required', 'string', 'max:255'],
            'scope' => ['required', Rule::in(['institute', 'department'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);
    }
}
