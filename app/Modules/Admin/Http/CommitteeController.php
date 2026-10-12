<?php

namespace App\Modules\Admin\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\Committee;
use App\Modules\Identity\Models\CommitteeMember;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Committees and their membership periods (IAM-004). */
class CommitteeController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize(Permissions::COMMITTEES_MANAGE);

        return Inertia::render('Admin/Committees/Index', [
            'committees' => Committee::query()->orderBy('name')
                ->with(['department:id,code', 'members' => fn ($q) => $q->with('user:id,name,username')->orderByDesc('starts_on')])
                ->get()
                ->map(fn (Committee $c) => [
                    ...$c->only('id', 'code', 'name', 'department_id', 'purpose', 'is_active'),
                    'department' => $c->department?->code,
                    'members' => $c->members->map(fn (CommitteeMember $m) => [
                        ...$m->only('id', 'role', 'is_voting'),
                        'voting_weight' => (float) $m->voting_weight,
                        'user' => $m->user->only('id', 'name', 'username'),
                        'starts_on' => $m->starts_on->toDateString(),
                        'ends_on' => $m->ends_on?->toDateString(),
                        'current' => $m->starts_on->lte(today()) && (! $m->ends_on || $m->ends_on->gte(today())),
                    ]),
                ]),
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'roles' => CommitteeMember::ROLES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::COMMITTEES_MANAGE);
        $committee = Committee::create($this->validated($request));
        $this->audit->record('admin.committee.created', $committee, $committee->only('code', 'name'));

        return back()->with('success', 'Committee created.');
    }

    public function update(Request $request, Committee $committee): RedirectResponse
    {
        $this->authorize(Permissions::COMMITTEES_MANAGE);
        $committee->fill($this->validated($request, $committee));
        $changes = $committee->getDirty();
        $committee->save();
        $this->audit->record('admin.committee.updated', $committee, ['changes' => $changes]);

        return back()->with('success', 'Committee updated.');
    }

    public function addMember(Request $request, Committee $committee): RedirectResponse
    {
        $this->authorize(Permissions::COMMITTEES_MANAGE);
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'role' => ['required', Rule::in(CommitteeMember::ROLES)],
            'voting_weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_voting' => ['boolean'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);
        $data['voting_weight'] ??= 1;
        $member = $committee->members()->create($data);
        $this->audit->record('admin.committee.member_added', $member, ['committee' => $committee->code] + $data);

        return back()->with('success', 'Member added.');
    }

    public function endMember(Request $request, CommitteeMember $member): RedirectResponse
    {
        $this->authorize(Permissions::COMMITTEES_MANAGE);
        $data = $request->validate(['ends_on' => ['required', 'date', 'after_or_equal:'.$member->starts_on->toDateString()]]);
        $member->update($data);
        $this->audit->record('admin.committee.member_ended', $member, $data);

        return back()->with('success', 'Membership end date set.');
    }

    private function validated(Request $request, ?Committee $committee = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('committees')->ignore($committee)],
            'name' => ['required', 'string', 'max:255'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);
    }
}
