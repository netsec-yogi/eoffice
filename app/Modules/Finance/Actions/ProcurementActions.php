<?php

namespace App\Modules\Finance\Actions;

use App\Modules\Finance\Models\Advance;
use App\Modules\Finance\Models\ProcurementCase;
use App\Modules\Finance\Services\ProcurementService;
use App\Modules\Workflow\Automation\AutomationAction;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Engine\WorkflowException;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;

/**
 * Register steps for purchase and advance workflows. Setting `step`:
 * record_indent, commit_budget, check_evaluation, issue_order,
 * record_receipt, accept_receipt, record_invoice, verify_invoice,
 * sanction_invoice, pay_invoice, record_advance, sanction_advance,
 * disburse_advance, settle_advance. All idempotent.
 */
class ProcurementActions implements AutomationAction
{
    public function __construct(private ProcurementService $procurement) {}

    public function label(): string
    {
        return 'Finance & purchase: update the register';
    }

    public function settings(): array
    {
        return ['step' => ['label' => 'Step (record_indent, commit_budget, check_evaluation, issue_order, record_receipt, accept_receipt, record_invoice, verify_invoice, sanction_invoice, pay_invoice, record_advance, sanction_advance, disburse_advance, settle_advance)', 'required' => true]];
    }

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        $case = fn () => ProcurementCase::query()->where('workflow_instance_id', $instance->id)->firstOrFail();
        try {
            return match ($config['step'] ?? '') {
                'record_indent' => AutomationResult::success(['case_number' => $this->procurement->recordIndent($instance)->number]),
                'commit_budget' => $this->done(fn () => $this->procurement->commitBudget($case())),
                'check_evaluation' => ($gaps = $this->procurement->evaluationGaps($case())) ? AutomationResult::failure(implode(' ', $gaps)) : $this->done(fn () => $case()->forceFill(['status' => 'evaluated'])->save()),
                'issue_order' => AutomationResult::success(['po_number' => $this->procurement->issueOrder($case(), null)->number]),
                'record_receipt' => AutomationResult::success(['grn' => $this->procurement->recordReceipt($instance, false)->number]),
                'accept_receipt' => AutomationResult::success(['grn' => $this->procurement->recordReceipt($instance, true)->number]),
                'record_invoice' => AutomationResult::success(['bill' => $this->procurement->recordInvoice($instance)->number]),
                'verify_invoice' => AutomationResult::success(['net_payable' => $this->procurement->markInvoice($instance, 'verified')->net_payable]),
                'sanction_invoice' => $this->done(fn () => $this->procurement->markInvoice($instance, 'sanctioned')),
                'pay_invoice' => $this->done(fn () => $this->procurement->pay($instance)),
                'record_advance', 'sanction_advance', 'disburse_advance' => $this->advance($instance, $config['step']),
                'settle_advance' => $this->settle($instance),
                default => AutomationResult::failure('Unknown register step.'),
            };
        } catch (WorkflowException $e) {
            return AutomationResult::failure($e->getMessage()); // e.g. budget no longer available
        }
    }

    private function done(callable $fn): AutomationResult
    {
        $fn();

        return AutomationResult::success();
    }

    private function advance(WorkflowInstance $instance, string $step): AutomationResult
    {
        $d = $instance->data;
        $a = Advance::query()->where('workflow_instance_id', $instance->id)->first()
            ?? (new Advance)->forceFill(['workflow_instance_id' => $instance->id, 'user_id' => $instance->initiator_id, 'status' => 'requested']);
        if ($step === 'record_advance' && $a->status === 'requested') {
            $a->forceFill(['purpose' => $d['purpose'], 'amount_requested' => (float) $d['amount_requested'], 'settle_by' => $d['settle_by'] ?? null, 'budget_head_id' => $d['budget_head'] ?? null])->save();
        } elseif ($step === 'sanction_advance' && $a->status === 'requested') {
            $a->forceFill(['amount_sanctioned' => (float) ($d['amount_sanctioned'] ?? $d['amount_requested']), 'status' => 'sanctioned'])->save();
        } elseif ($step === 'disburse_advance' && $a->status === 'sanctioned') {
            $a->forceFill(['status' => 'disbursed', 'disbursement_reference' => $d['disbursement_reference'] ?? null])->save();
        }

        return AutomationResult::success(['advance_status' => $a->status]);
    }

    private function settle(WorkflowInstance $instance): AutomationResult
    {
        $a = Advance::query()->where('user_id', $instance->initiator_id)->findOrFail($instance->data['advance']);
        if ($a->status !== 'settled') {
            $a->forceFill(['status' => 'settled', 'spent' => (float) $instance->data['spent'], 'balance' => (float) $instance->data['balance'], 'settlement_instance_id' => $instance->id, 'settled_at' => now()])->save();
        }

        return AutomationResult::success(['balance' => $a->balance]);
    }
}
