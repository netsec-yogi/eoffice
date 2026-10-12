<?php

namespace App\Modules\ComputerCentre\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\ComputerCentre\Models\LicenceAllocation;
use App\Modules\ComputerCentre\Models\SoftwareTitle;
use App\Modules\ComputerCentre\Services\LicenceService;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LicenceController extends Controller
{
    public function __construct(private LicenceService $licences) {}

    public function index(): Response
    {
        $this->authorize(Permissions::CC_MANAGE);

        return Inertia::render('ComputerCentre/Licences/Index', [
            'titles' => SoftwareTitle::query()->withCount('activeAllocations')->orderBy('name')->get()
                ->map(fn (SoftwareTitle $t) => [
                    ...$t->only('id', 'code', 'name', 'publisher', 'licence_type', 'seats', 'agreement_reference', 'is_active'),
                    'expires_on' => $t->expires_on?->toDateString(),
                    'used' => $t->active_allocations_count,
                    'expiring' => $t->expires_on && $t->expires_on->lte(today()->addDays(60)),
                ]),
            'allocations' => LicenceAllocation::query()->whereNull('revoked_at')->with(['title:id,code,name', 'user:id,name,username'])->latest('allocated_at')->limit(200)->get()
                ->map(fn ($a) => ['id' => $a->id, 'title' => $a->title->name, 'code' => $a->title->code, 'user' => $a->user->only('id', 'name', 'username'), 'allocated_at' => $a->allocated_at, 'workflow_instance_id' => $a->workflow_instance_id]),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize(Permissions::CC_MANAGE);
        $title = SoftwareTitle::create($this->validated($request));
        $audit->record('cc.licence_title.created', $title, $title->only('code', 'seats', 'licence_type'));

        return back()->with('success', 'Software added.');
    }

    public function update(Request $request, SoftwareTitle $title, AuditLogger $audit): RedirectResponse
    {
        $this->authorize(Permissions::CC_MANAGE);
        $data = $this->validated($request, $title);
        if ($data['seats'] !== null && $data['seats'] < $title->activeAllocations()->count()) {
            return back()->withErrors(['seats' => 'More seats are allocated than this. Revoke some first.']);
        }
        $title->fill($data);
        $changes = $title->getDirty();
        $title->save();
        $audit->record('cc.licence_title.updated', $title, ['changes' => $changes]);

        return back()->with('success', 'Software updated.');
    }

    public function allocate(Request $request, SoftwareTitle $title): RedirectResponse
    {
        $this->authorize(Permissions::CC_MANAGE);
        $data = $request->validate(['user_id' => ['required', 'exists:users,id']]);
        $this->licences->allocate($title, User::query()->findOrFail($data['user_id']), $request->user());

        return back()->with('success', 'Seat allocated.');
    }

    public function revoke(Request $request, LicenceAllocation $allocation): RedirectResponse
    {
        $this->authorize(Permissions::CC_MANAGE);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->licences->revoke($allocation, $request->user(), $data['reason']);

        return back()->with('success', 'Seat released.');
    }

    private function validated(Request $request, ?SoftwareTitle $title = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/', Rule::unique('software_titles')->ignore($title)],
            'name' => ['required', 'string', 'max:255'],
            'publisher' => ['nullable', 'string', 'max:255'],
            'licence_type' => ['required', Rule::in(['per_seat', 'site', 'subscription', 'open_source'])],
            'seats' => ['nullable', 'integer', 'min:0'],
            'expires_on' => ['nullable', 'date'],
            'agreement_reference' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
    }
}
