<?php

namespace App\Modules\Registrar\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;
use App\Modules\Registrar\Models\LeaveLedgerEntry;
use App\Modules\Registrar\Models\LeaveRecord;
use App\Modules\Registrar\Models\LeaveType;
use App\Modules\Registrar\Services\LeaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeaveController extends Controller
{
    public function __construct(private LeaveService $leave) {}

    /** The signed-in user's balances, leave and ledger. */
    public function mine(Request $request): Response
    {
        return Inertia::render('Leave/Mine', $this->summary($request->user()) + ['self' => true]);
    }

    /** Leave administration: types, and one person's ledger with adjustments. */
    public function admin(Request $request): Response
    {
        $this->authorize(Permissions::REGISTRAR_MANAGE);
        $userId = $request->integer('user');
        $person = $userId ? User::query()->findOrFail($userId) : null;

        return Inertia::render('Leave/Admin', [
            'types' => LeaveType::query()->orderBy('name')->get(),
            'person' => $person ? ['id' => $person->id, 'name' => $person->name, 'username' => $person->username, ...$this->summary($person)] : null,
        ]);
    }

    public function storeType(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize(Permissions::REGISTRAR_MANAGE);
        $type = LeaveType::create($this->typeData($request));
        $audit->record('leave.type.created', $type, $type->only('code', 'has_balance', 'counts_holidays'));

        return back()->with('success', 'Leave type created.');
    }

    public function updateType(Request $request, LeaveType $type, AuditLogger $audit): RedirectResponse
    {
        $this->authorize(Permissions::REGISTRAR_MANAGE);
        $type->fill($this->typeData($request, $type));
        $changes = $type->getDirty();
        $type->save();
        $audit->record('leave.type.updated', $type, ['changes' => $changes]);

        return back()->with('success', 'Leave type updated.');
    }

    /** Credit, debit-adjust or lapse days for one person, or credit everyone of a category. */
    public function post(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::REGISTRAR_MANAGE);
        $data = $request->validate([
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'kind' => ['required', Rule::in(['credit', 'adjustment', 'lapse'])],
            'days' => ['required', 'numeric', 'between:-365,365', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
            'user_id' => ['nullable', 'exists:users,id'],
            'category' => ['required_without:user_id', 'nullable', Rule::in(['faculty', 'staff'])],
        ]);
        $type = LeaveType::query()->findOrFail($data['leave_type_id']);
        abort_unless($type->has_balance, 422, 'This leave type keeps no balance.');
        $days = $data['kind'] === 'lapse' ? -abs((float) $data['days']) : ($data['kind'] === 'credit' ? abs((float) $data['days']) : (float) $data['days']);
        $people = isset($data['user_id']) ? User::query()->whereKey($data['user_id'])->get() : User::query()->active()->where('category', $data['category'])->get();
        foreach ($people->filter(fn ($u) => $type->eligible($u->category)) as $person) {
            $this->leave->post($person, $type, $data['kind'], $days, $data['reason'], $request->user());
        }

        return back()->with('success', 'Posted to '.$people->count().' ledger(s).');
    }

    private function summary(User $user): array
    {
        $types = LeaveType::query()->where('is_active', true)->orderBy('name')->get()->filter(fn ($t) => $t->eligible($user->category));

        return [
            'balances' => $types->map(fn ($t) => [
                'code' => $t->code, 'name' => $t->name, 'has_balance' => $t->has_balance,
                'balance' => $t->has_balance ? $this->leave->ledgerBalance($user, $t) : null,
                'pending' => $this->leave->pending($user, $t),
                'available' => $t->has_balance ? $this->leave->available($user, $t) : null,
            ])->values(),
            'records' => LeaveRecord::query()->with('type:id,name')->where('user_id', $user->id)->orderByDesc('from_date')->limit(50)->get()
                ->map(fn ($r) => ['id' => $r->id, 'type' => $r->type->name, 'from' => $r->from_date->toDateString(), 'to' => $r->to_date->toDateString(), 'days' => $r->days, 'status' => $r->status, 'instance' => $r->workflow_instance_id]),
            'ledger' => LeaveLedgerEntry::query()->with('type:id,code', 'creator:id,name')->where('user_id', $user->id)->orderByDesc('id')->limit(100)->get()
                ->map(fn ($e) => ['id' => $e->id, 'type' => $e->type->code, 'kind' => $e->kind, 'days' => $e->days, 'reason' => $e->reason, 'by' => $e->creator?->name ?? 'Workflow', 'at' => $e->created_at, 'instance' => $e->workflow_instance_id]),
        ];
    }

    private function typeData(Request $request, ?LeaveType $type = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:16', 'regex:/^[A-Z0-9_]+$/', Rule::unique('leave_types')->ignore($type)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'has_balance' => ['boolean'],
            'counts_holidays' => ['boolean'],
            'allow_half_day' => ['boolean'],
            'max_days_per_application' => ['nullable', 'numeric', 'min:0.5'],
            'min_notice_days' => ['nullable', 'integer', 'min:0'],
            'certificate_after_days' => ['nullable', 'numeric', 'min:0'],
            'eligible_categories' => ['nullable', 'array'],
            'eligible_categories.*' => [Rule::in(['faculty', 'staff', 'student'])],
            'is_active' => ['boolean'],
        ]);
    }
}
