<?php

namespace App\Modules\Registrar\Validators;

use App\Modules\Identity\Models\User;
use App\Modules\Registrar\Models\ReimbursementClaim;
use App\Modules\Registrar\Models\TravelAuthorisation;
use App\Modules\Workflow\Forms\SubmissionValidator;
use App\Modules\Workflow\Models\WorkflowInstance;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * TA/DA, LTC and medical claims. Travel claims must refer to the person's
 * own approved journey with no other live claim, and bills must fall within
 * the journey (± margin). Fields: authorisation (travel), bills table with
 * date and amount. Adds claimed_amount and advance.
 */
class ClaimValidator implements SubmissionValidator
{
    public function __construct(private string $kind = 'tour') {}

    public function label(): string
    {
        return ['tour' => 'Claim: TA/DA against an approved tour', 'ltc' => 'Claim: LTC against an approved journey', 'medical' => 'Claim: medical bills'][$this->kind];
    }

    public function provides(): array
    {
        return ['claimed_amount', 'advance', 'journey', 'net_claim'];
    }

    public function validate(array $data, User $initiator, WorkflowInstance $instance): array
    {
        $bills = $data['bills'] ?? [];
        $total = round(array_sum(array_map(fn ($b) => (float) ($b['amount'] ?? 0), $bills)), 2);
        if ($total <= 0) {
            throw ValidationException::withMessages(['bills' => 'Enter at least one bill with an amount.']);
        }
        if ($this->kind === 'medical') {
            return ['claimed_amount' => $total, 'advance' => 0, 'net_claim' => $total];
        }

        $auth = TravelAuthorisation::query()->where(['user_id' => $initiator->id, 'kind' => $this->kind, 'status' => 'approved'])->find($data['authorisation'] ?? 0)
            ?? throw ValidationException::withMessages(['authorisation' => 'Choose one of your approved journeys.']);
        $live = ReimbursementClaim::query()->where('travel_authorisation_id', $auth->id)->whereNotIn('status', ['rejected', 'cancelled'])
            ->where('workflow_instance_id', '!=', $instance->id)->exists();
        if ($live) {
            throw ValidationException::withMessages(['authorisation' => 'A claim for this journey already exists.']);
        }
        $margin = (int) config('registrar.claim_bill_margin_days');
        foreach ($bills as $i => $bill) {
            if (! empty($bill['date']) && ! CarbonImmutable::parse($bill['date'])->between($auth->from_date->subDays($margin), $auth->to_date->addDays($margin))) {
                throw ValidationException::withMessages(["bills.{$i}.date" => 'This bill is outside the journey dates.']);
            }
        }

        $advance = (float) ($auth->advance_sanctioned ?? 0);

        return ['claimed_amount' => $total, 'advance' => $advance, 'net_claim' => round($total - $advance, 2), 'journey' => "{$auth->destination}, {$auth->from_date->toDateString()} – {$auth->to_date->toDateString()}"];
    }
}
