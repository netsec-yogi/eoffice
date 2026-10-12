<?php

namespace App\Modules\Workflow\Engine;

use App\Modules\Identity\Models\Authority;
use App\Modules\Identity\Models\AuthorityAssignment;
use App\Modules\Identity\Models\Committee;
use App\Modules\Identity\Models\CommitteeMember;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Support\Collection;

/**
 * Turns an assignee specification from a workflow node into people
 * (WF-011). Specs never contain employee IDs except the explicit `user`
 * type; routine routing uses authorities, reporting lines and committees,
 * so a change of office holder needs no workflow change.
 *
 *   {"type": "authority", "authority": "HOD", "department": "initiator"}
 *   {"type": "authority", "authority": "REGISTRAR"}
 *   {"type": "reporting_manager", "levels": 1}
 *   {"type": "role", "role": "cc_operator", "department": "initiator"}
 *   {"type": "committee", "committee": "IT-PURCHASE"}
 *   {"type": "field", "field": "supervisor"}      (an employee selector on the form)
 *   {"type": "user", "user_id": 42}
 *   {"type": "initiator"}
 */
class AssigneeResolver
{
    public const TYPES = ['authority', 'reporting_manager', 'role', 'committee', 'field', 'user', 'initiator'];

    /** @return Collection<int, User> active users, unique */
    public function resolve(array $spec, WorkflowInstance $instance): Collection
    {
        $instance->loadMissing('initiator');

        $users = match ($spec['type'] ?? null) {
            'user' => User::query()->whereKey($spec['user_id'] ?? 0)->get(),
            'initiator' => collect([$instance->initiator]),
            'role' => User::role($spec['role'] ?? '__none__')
                ->when(($spec['department'] ?? null) !== null, fn ($q) => $q->where('department_id', $this->departmentId($spec, $instance)))
                ->get(),
            'authority' => $this->authorityHolders($spec, $instance),
            'reporting_manager' => $this->manager($instance->initiator, max(1, (int) ($spec['levels'] ?? 1))),
            'committee' => $this->committeeMembers($spec['committee'] ?? '', votingOnly: false)->pluck('user'),
            'field' => User::query()->whereKey((int) data_get($instance->data, $spec['field'] ?? '__none__'))->get(),
            default => collect(),
        };

        return $users->filter(fn ($u) => $u instanceof User && $u->canSignIn())->unique('id')->values();
    }

    /** Human-readable description of a spec, for timelines and the designer. */
    public function describe(array $spec): string
    {
        return match ($spec['type'] ?? null) {
            'authority' => ($spec['authority'] ?? '?').(($spec['department'] ?? null) === 'initiator' ? " (initiator's department)" : ''),
            'reporting_manager' => ((int) ($spec['levels'] ?? 1)) > 1 ? "Reporting officer, {$spec['levels']} levels up" : 'Reporting officer',
            'role' => 'Role '.($spec['role'] ?? '?'),
            'committee' => 'Committee '.($spec['committee'] ?? '?'),
            'field' => 'Person named in field '.($spec['field'] ?? '?'),
            'user' => 'User #'.($spec['user_id'] ?? '?'),
            'initiator' => 'Initiator',
            default => 'Unknown',
        };
    }

    /**
     * Current voting members of a committee with their weights.
     *
     * @return Collection<int, CommitteeMember>
     */
    public function committeeMembers(string $code, bool $votingOnly = true): Collection
    {
        $committee = Committee::query()->where('code', $code)->where('is_active', true)->first();
        if (! $committee) {
            return collect();
        }

        return $committee->members()->current()->with('user')
            ->when($votingOnly, fn ($q) => $q->where('is_voting', true))
            ->get()
            ->filter(fn ($m) => $m->user?->canSignIn())
            ->unique('user_id')
            ->values();
    }

    private function authorityHolders(array $spec, WorkflowInstance $instance): Collection
    {
        $authority = Authority::query()->where('code', $spec['authority'] ?? '')->where('is_active', true)->first();
        if (! $authority) {
            return collect();
        }

        $departmentId = $authority->isDepartmentScoped() ? $this->departmentId($spec + ['department' => 'initiator'], $instance) : null;
        $holders = $this->holders($authority, $departmentId);

        // Optional, explicit: walk up the department tree when a unit has no holder.
        while ($holders->isEmpty() && $departmentId && ! empty($spec['fallback_to_parent'])) {
            $departmentId = Department::query()->whereKey($departmentId)->value('parent_id');
            $holders = $departmentId ? $this->holders($authority, $departmentId) : collect();
        }

        return $holders;
    }

    private function holders(Authority $authority, ?int $departmentId): Collection
    {
        $assignments = AuthorityAssignment::query()->current()->with('user')
            ->where('authority_id', $authority->id)
            ->when($authority->isDepartmentScoped(), fn ($q) => $q->where('department_id', $departmentId))
            ->get();

        // A regular holder takes precedence over an acting one only when both
        // are in force; normally there is one or the other.
        $regular = $assignments->where('is_acting', false);

        return ($regular->isNotEmpty() ? $regular : $assignments)->pluck('user');
    }

    private function departmentId(array $spec, WorkflowInstance $instance): ?int
    {
        return match ($spec['department'] ?? 'initiator') {
            'fixed' => isset($spec['department_id']) ? (int) $spec['department_id'] : null,
            'field' => ($v = data_get($instance->data, $spec['department_field'] ?? '__none__')) ? (int) $v : null,
            default => $instance->department_id ?? $instance->initiator->department_id,
        };
    }

    private function manager(User $user, int $levels): Collection
    {
        $current = $user;
        for ($i = 0; $i < $levels; $i++) {
            $current = $current->reports_to_id ? User::query()->find($current->reports_to_id) : null;
            if (! $current) {
                return collect();
            }
        }

        return collect([$current]);
    }
}
