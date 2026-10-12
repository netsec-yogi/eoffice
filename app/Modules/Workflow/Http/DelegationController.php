<?php

namespace App\Modules\Workflow\Http;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\Delegation;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;
use App\Modules\Workflow\Engine\DelegationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DelegationController extends Controller
{
    public function __construct(private DelegationService $delegations) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $present = fn (Delegation $d) => [
            ...$d->only('id', 'reason', 'status', 'workflow_categories', 'allowed_actions'),
            'delegator' => $d->delegator->only('id', 'name'),
            'delegate' => $d->delegate->only('id', 'name'),
            'starts_at' => $d->starts_at->toIso8601String(),
            'ends_at' => $d->ends_at->toIso8601String(),
            'in_force' => $d->status === 'active' && $d->starts_at->isPast() && $d->ends_at->isFuture(),
        ];

        return Inertia::render('Delegations/Index', [
            'given' => Delegation::query()->where('delegator_id', $user->id)->with('delegator:id,name', 'delegate:id,name')->latest()->limit(50)->get()->map($present),
            'received' => Delegation::query()->where('delegate_id', $user->id)->with('delegator:id,name', 'delegate:id,name')->latest()->limit(50)->get()->map($present),
            'categories' => config('workflow.categories'),
            'canManage' => $user->can(Permissions::DELEGATIONS_MANAGE),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'delegator_id' => ['nullable', 'exists:users,id'],
            'delegate_id' => ['required', 'exists:users,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at', 'after:now'],
            'reason' => ['required', 'string', 'max:1000'],
            'workflow_categories' => ['nullable', 'array'],
            'workflow_categories.*' => ['string', 'max:64'],
            'allowed_actions' => ['nullable', 'array'],
            'allowed_actions.*' => [Rule::in(['approve', 'reject', 'return', 'forward', 'hold'])],
        ]);
        $delegator = $request->user();
        if (! empty($data['delegator_id']) && (int) $data['delegator_id'] !== $delegator->id) {
            $this->authorize(Permissions::DELEGATIONS_MANAGE); // on someone else's behalf
            $delegator = User::query()->findOrFail($data['delegator_id']);
        }
        $this->delegations->create($delegator, $data, $request->user());

        return back()->with('success', 'Delegation saved.');
    }

    public function destroy(Request $request, Delegation $delegation): RedirectResponse
    {
        abort_unless($delegation->delegator_id === $request->user()->id || $request->user()->can(Permissions::DELEGATIONS_MANAGE), 403);
        $this->delegations->revoke($delegation, $request->user());

        return back()->with('success', 'Delegation revoked.');
    }
}
