<?php

namespace App\Modules\Workflow\Engine;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\Delegation;
use App\Modules\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Time-bounded delegation of approval work (IAM-007). A delegate acts on
 * the delegator's behalf -- the history always records both -- and can
 * never do what the delegator could not, approve their own request, or
 * pass the work on again (no chains).
 */
class DelegationService
{
    public function __construct(private AuditLogger $audit) {}

    /** @param array{delegate_id:int, starts_at:string, ends_at:string, reason:string, workflow_categories?:?array, department_ids?:?array, allowed_actions?:?array} $data */
    public function create(User $delegator, array $data, User $by): Delegation
    {
        $delegate = User::query()->findOrFail($data['delegate_id']);
        if ($delegate->is($delegator)) {
            throw ValidationException::withMessages(['delegate_id' => 'You cannot delegate to yourself.']);
        }
        if (! $delegate->canSignIn() || in_array($delegate->category, ['student', 'vendor', 'external'], true)) {
            throw ValidationException::withMessages(['delegate_id' => 'The delegate must be an active member of staff or faculty.']);
        }

        return DB::transaction(function () use ($delegator, $delegate, $data, $by) {
            $overlap = Delegation::query()->where('delegator_id', $delegator->id)->where('status', 'active')
                ->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])
                ->lockForUpdate()->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['starts_at' => 'You already have a delegation in force during this period. Revoke it first.']);
            }

            $delegation = new Delegation([
                'delegator_id' => $delegator->id,
                'delegate_id' => $delegate->id,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'reason' => $data['reason'],
                'workflow_categories' => $data['workflow_categories'] ?? null ?: null,
                'department_ids' => $data['department_ids'] ?? null ?: null,
                'allowed_actions' => $data['allowed_actions'] ?? null ?: null,
            ]);
            $requiresApproval = config('workflow.delegation_requires_approval') && ! $by->can('delegations.manage');
            $delegation->forceFill([
                'status' => $requiresApproval ? 'pending_approval' : 'active',
                'approved_by' => $requiresApproval ? null : $by->id,
            ])->save();

            $this->audit->record('delegation.created', $delegation, [
                'delegator_id' => $delegator->id, 'delegate_id' => $delegate->id, 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'],
                'categories' => $delegation->workflow_categories, 'actions' => $delegation->allowed_actions, 'status' => $delegation->status,
            ], actor: $by, onBehalfOf: $by->is($delegator) ? null : $delegator);

            return $delegation;
        });
    }

    public function revoke(Delegation $delegation, User $by): void
    {
        if ($delegation->status === 'revoked') {
            return;
        }
        $delegation->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $by->id])->save();
        $this->audit->record('delegation.revoked', $delegation, [], actor: $by);
    }

    /** The delegate acting for $principal right now for this kind of work, if any. */
    public function delegateFor(User $principal, ?string $category, ?int $departmentId, string $action = 'approve', ?CarbonInterface $at = null): ?User
    {
        $delegation = Delegation::query()->inForce($at)->where('delegator_id', $principal->id)->with('delegate')->get()
            ->first(fn (Delegation $d) => $d->covers($category, $departmentId, $action) && $d->delegate?->canSignIn());

        return $delegation?->delegate;
    }

    /** Does $delegate currently hold a delegation from $principal covering this work? */
    public function isDelegateOf(User $delegate, User $principal, ?string $category, ?int $departmentId, string $action): bool
    {
        return Delegation::query()->inForce()->where('delegator_id', $principal->id)->where('delegate_id', $delegate->id)->get()
            ->contains(fn (Delegation $d) => $d->covers($category, $departmentId, $action));
    }

    /** @return list<int> users who have delegated work to $delegate right now */
    public function principalsOf(User $delegate): array
    {
        return Delegation::query()->inForce()->where('delegate_id', $delegate->id)->pluck('delegator_id')->unique()->values()->all();
    }
}
