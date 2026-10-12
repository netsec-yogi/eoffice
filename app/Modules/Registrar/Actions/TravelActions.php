<?php

namespace App\Modules\Registrar\Actions;

use App\Modules\Registrar\Models\ReimbursementClaim;
use App\Modules\Registrar\Models\TravelAuthorisation;
use App\Modules\Workflow\Automation\AutomationAction;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;

/**
 * Registers behind travel and claim workflows. Settings: `step` =
 * record_journey | approve_journey | record_claim | sanction_claim | mark_paid,
 * `kind` = tour | ltc | medical. Idempotent: keyed by the request.
 */
class TravelActions implements AutomationAction
{
    public function label(): string
    {
        return 'Travel & claims: update the register';
    }

    public function settings(): array
    {
        return [
            'step' => ['label' => 'Step (record_journey, approve_journey, record_claim, sanction_claim, mark_paid)', 'required' => true],
            'kind' => ['label' => 'Kind (tour, ltc, medical)', 'required' => true],
        ];
    }

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        $d = $instance->data;
        $kind = $config['kind'] ?? 'tour';

        switch ($config['step'] ?? '') {
            case 'record_journey':
                $a = (TravelAuthorisation::query()->where('workflow_instance_id', $instance->id)->first() ?? (new TravelAuthorisation)->forceFill(['workflow_instance_id' => $instance->id]));
                $a->forceFill([
                    'user_id' => $instance->initiator_id, 'kind' => $kind, 'purpose' => (string) ($d['purpose'] ?? ''), 'destination' => (string) ($d['destination'] ?? ''),
                    'from_date' => $d['from_date'], 'to_date' => $d['to_date'], 'block' => $d['block'] ?? null, 'travellers' => $d['travellers'] ?? null,
                    'advance_requested' => (float) ($d['advance_requested'] ?? 0),
                ]);
                if (! $a->exists) {
                    $a->status = 'pending';
                }
                $a->save();

                return AutomationResult::success();

            case 'approve_journey':
                $a = TravelAuthorisation::query()->where('workflow_instance_id', $instance->id)->firstOrFail();
                $a->forceFill(['status' => 'approved', 'advance_sanctioned' => (float) ($d['advance_sanctioned'] ?? $d['advance_requested'] ?? 0)])->save();

                return AutomationResult::success(['advance_sanctioned' => $a->advance_sanctioned]);

            case 'record_claim':
                $c = (ReimbursementClaim::query()->where('workflow_instance_id', $instance->id)->first() ?? (new ReimbursementClaim)->forceFill(['workflow_instance_id' => $instance->id]));
                $c->forceFill([
                    'user_id' => $instance->initiator_id, 'kind' => $kind, 'travel_authorisation_id' => $kind === 'medical' ? null : ($d['authorisation'] ?? null),
                    'claimed_amount' => (float) $d['claimed_amount'], 'advance_adjusted' => (float) ($d['advance'] ?? 0),
                ]);
                if (! $c->exists) {
                    $c->status = 'pending';
                }
                $c->save();
                if ($c->travel_authorisation_id) {
                    TravelAuthorisation::query()->whereKey($c->travel_authorisation_id)->update(['claim_status' => 'claimed']);
                }

                return AutomationResult::success();

            case 'sanction_claim':
                $c = ReimbursementClaim::query()->where('workflow_instance_id', $instance->id)->firstOrFail();
                $admissible = isset($d['admissible_amount']) && $d['admissible_amount'] !== null ? (float) $d['admissible_amount'] : $c->claimed_amount;
                $c->forceFill(['admissible_amount' => $admissible, 'net_payable' => round($admissible - $c->advance_adjusted, 2), 'status' => 'sanctioned'])->save();

                return AutomationResult::success(['net_payable' => $c->net_payable]);

            case 'mark_paid':
                $c = ReimbursementClaim::query()->where('workflow_instance_id', $instance->id)->firstOrFail();
                $c->forceFill(['status' => 'paid', 'payment_reference' => $d['payment_reference'] ?? null, 'paid_on' => $d['paid_on'] ?? today()->toDateString()])->save();
                if ($c->travel_authorisation_id) {
                    TravelAuthorisation::query()->whereKey($c->travel_authorisation_id)->update(['claim_status' => 'settled']);
                }

                return AutomationResult::success();
        }

        return AutomationResult::failure('Unknown register step.');
    }
}
