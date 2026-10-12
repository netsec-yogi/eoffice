<?php

namespace App\Modules\Finance\Validators;

use App\Modules\Finance\Models\Advance;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Forms\SubmissionValidator;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Validation\ValidationException;

/** Settlement of one's own disbursed advance; computes spent and balance (FIN-001). */
class AdvanceSettlementValidator implements SubmissionValidator
{
    public function label(): string
    {
        return 'Advance: settlement against the expense statement';
    }

    public function provides(): array
    {
        // The advance's own particulars are copied in: once settled it no longer
        // appears among "open advances", but the statement must still show it.
        return ['spent', 'balance', 'advance_amount', 'advance_purpose', 'advance_reference', 'advance_drawn_on', 'excess_claimed', 'balance_refundable'];
    }

    public function validate(array $data, User $initiator, WorkflowInstance $instance): array
    {
        $advance = Advance::query()->where(['user_id' => $initiator->id, 'status' => 'disbursed'])->find($data['advance'] ?? 0)
            ?? throw ValidationException::withMessages(['advance' => 'Choose one of your unsettled advances.']);
        $spent = round(array_sum(array_map(fn ($r) => (float) ($r['amount'] ?? 0), $data['expenses'] ?? [])), 2);

        $balance = round($advance->amount_sanctioned - $spent, 2);

        return [
            'spent' => $spent, 'balance' => $balance, 'advance_amount' => $advance->amount_sanctioned,
            'advance_purpose' => $advance->purpose, 'advance_reference' => $advance->disbursement_reference,
            'advance_drawn_on' => WorkflowInstance::query()->whereKey($advance->workflow_instance_id)->value('completed_at')?->toDateString(),
            'excess_claimed' => max(0, -$balance), 'balance_refundable' => max(0, $balance),
        ];
    }
}
