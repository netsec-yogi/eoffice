<?php

namespace App\Modules\Workflow\Engine;

use InvalidArgumentException;

/**
 * How a committee reaches a decision (WF-009). The rule is explicit
 * configuration on the workflow step -- nothing is assumed:
 *
 *   any       one approval carries it
 *   all       unanimous: every voting member approves (abstentions block)
 *   majority  approvals > half of all voting members
 *   quorum    at least `quorum` approvals
 *   weighted  approving weight >= `threshold` x total weight
 *
 * The decision is taken as soon as the outcome can no longer change, so a
 * committee does not wait for votes that cannot matter.
 */
final class CommitteeRule
{
    public const RULES = ['any', 'all', 'majority', 'quorum', 'weighted'];

    /**
     * @param  list<array{vote: ?string, weight: float}>  $members  vote: approve, reject, abstain or null (pending)
     * @return 'approve'|'reject'|null null while undecided
     */
    public static function decide(string $rule, array $members, ?int $quorum = null, ?float $threshold = null): ?string
    {
        $n = count($members);
        if ($n === 0) {
            return 'reject';
        }
        $approve = count(array_filter($members, fn ($m) => $m['vote'] === 'approve'));
        $reject = count(array_filter($members, fn ($m) => $m['vote'] === 'reject'));
        $pending = count(array_filter($members, fn ($m) => $m['vote'] === null));
        $nonApproving = $n - $approve - $pending;

        return match ($rule) {
            'any' => $approve >= 1 ? 'approve' : ($pending === 0 ? 'reject' : null),
            'all' => $nonApproving > 0 ? 'reject' : ($pending === 0 ? 'approve' : null),
            'majority' => $approve * 2 > $n ? 'approve' : (($approve + $pending) * 2 <= $n ? 'reject' : null),
            'quorum' => self::quorum($approve, $pending, $quorum ?? throw new InvalidArgumentException('quorum rule needs a quorum')),
            'weighted' => self::weighted($members, $threshold ?? throw new InvalidArgumentException('weighted rule needs a threshold')),
            default => throw new InvalidArgumentException("Unknown committee rule [{$rule}]."),
        };
    }

    private static function quorum(int $approve, int $pending, int $quorum): ?string
    {
        return $approve >= $quorum ? 'approve' : ($approve + $pending < $quorum ? 'reject' : null);
    }

    private static function weighted(array $members, float $threshold): ?string
    {
        $total = array_sum(array_column($members, 'weight'));
        $for = array_sum(array_map(fn ($m) => $m['vote'] === 'approve' ? $m['weight'] : 0, $members));
        $open = array_sum(array_map(fn ($m) => $m['vote'] === null ? $m['weight'] : 0, $members));
        $needed = $threshold * $total;

        return $for >= $needed - 1e-9 ? 'approve' : ($for + $open < $needed - 1e-9 ? 'reject' : null);
    }
}
