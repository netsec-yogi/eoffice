<?php

namespace App\Modules\Finance\Validators;

use App\Modules\Finance\Models\BudgetHead;
use App\Modules\Finance\Services\BudgetService;
use App\Modules\Finance\Services\ProcurementRules;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Forms\SubmissionValidator;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Validation\ValidationException;

/**
 * Indent: prices each line, totals the estimate on the server, checks the
 * budget head has room (FIN-002), and records which procurement method the
 * published rules prescribe, with the rule version and inputs (PROC-002).
 */
class IndentValidator implements SubmissionValidator
{
    public function __construct(private BudgetService $budget, private ProcurementRules $rules) {}

    public function label(): string
    {
        return 'Purchase: estimate, budget availability and procurement method';
    }

    public function provides(): array
    {
        return ['estimated_value', 'procurement_method', 'committee_required', 'quotations_required', 'procurement_decision'];
    }

    public function validate(array $data, User $initiator, WorkflowInstance $instance): array
    {
        $items = array_map(fn ($i) => $i + ['amount' => round((float) $i['qty'] * (float) $i['est_unit_price'], 2)], $data['items'] ?? []);
        $value = round(array_sum(array_column($items, 'amount')), 2);
        if ($value <= 0) {
            throw ValidationException::withMessages(['items' => 'Enter quantities and estimated prices.']);
        }
        $head = BudgetHead::query()->where('is_active', true)->find($data['budget_head'] ?? 0)
            ?? throw ValidationException::withMessages(['budget_head' => 'Choose an active budget head.']);
        if ($head->department_id && $head->department_id !== $initiator->department_id) {
            throw ValidationException::withMessages(['budget_head' => 'This budget head belongs to another department.']);
        }
        if (($available = $this->budget->available($head)) < $value) {
            throw ValidationException::withMessages(['budget_head' => 'Only ₹'.number_format($available, 2)." is available under {$head->code}; the estimate is ₹".number_format($value, 2).'.']);
        }
        $decision = $this->rules->decide($value);

        return [
            'items' => $items, 'estimated_value' => $value,
            'procurement_method' => $decision['method'], 'committee_required' => $decision['committee'],
            'quotations_required' => $decision['quotations'], 'procurement_decision' => $decision,
        ];
    }
}
