<?php

namespace Tests\Unit;

use App\Modules\Workflow\Engine\CommitteeRule;
use PHPUnit\Framework\TestCase;

/** WF-009 */
class CommitteeRuleTest extends TestCase
{
    private function votes(string ...$v): array
    {
        return array_map(fn ($x) => ['vote' => $x === '-' ? null : $x, 'weight' => 1.0], $v);
    }

    public function test_any(): void
    {
        $this->assertSame('approve', CommitteeRule::decide('any', $this->votes('approve', '-', '-')));
        $this->assertNull(CommitteeRule::decide('any', $this->votes('reject', '-')));
        $this->assertSame('reject', CommitteeRule::decide('any', $this->votes('reject', 'reject')));
    }

    public function test_all_is_unanimous_and_abstention_blocks(): void
    {
        $this->assertNull(CommitteeRule::decide('all', $this->votes('approve', '-')));
        $this->assertSame('approve', CommitteeRule::decide('all', $this->votes('approve', 'approve')));
        $this->assertSame('reject', CommitteeRule::decide('all', $this->votes('approve', 'abstain', '-')));
    }

    public function test_majority_of_all_members(): void
    {
        $this->assertSame('approve', CommitteeRule::decide('majority', $this->votes('approve', 'approve', 'approve', '-', '-')));
        $this->assertNull(CommitteeRule::decide('majority', $this->votes('approve', 'approve', '-', '-')));
        $this->assertSame('reject', CommitteeRule::decide('majority', $this->votes('approve', 'reject', 'reject', '-')), '2 of 4 cannot be exceeded');
        $this->assertSame('reject', CommitteeRule::decide('majority', $this->votes('approve', 'abstain')));
    }

    public function test_quorum(): void
    {
        $this->assertSame('approve', CommitteeRule::decide('quorum', $this->votes('approve', 'approve', '-', '-'), 2));
        $this->assertNull(CommitteeRule::decide('quorum', $this->votes('approve', 'reject', '-'), 2));
        $this->assertSame('reject', CommitteeRule::decide('quorum', $this->votes('approve', 'reject', 'reject'), 2));
    }

    public function test_weighted(): void
    {
        $members = [['vote' => 'approve', 'weight' => 2.0], ['vote' => null, 'weight' => 1.0], ['vote' => 'reject', 'weight' => 1.0]];
        $this->assertSame('approve', CommitteeRule::decide('weighted', $members, threshold: 0.5));
        $this->assertNull(CommitteeRule::decide('weighted', $members, threshold: 0.75));
        $members[1]['vote'] = 'reject';
        $this->assertSame('reject', CommitteeRule::decide('weighted', $members, threshold: 0.75));
    }

    public function test_rules_need_their_parameters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CommitteeRule::decide('quorum', $this->votes('approve'));
    }
}
