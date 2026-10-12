<?php

namespace App\Modules\Registrar\Validators;

use App\Modules\Identity\Models\User;
use App\Modules\Registrar\Models\TravelAuthorisation;
use App\Modules\Workflow\Forms\SubmissionValidator;
use App\Modules\Workflow\Models\WorkflowInstance;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Tour / LTC applications: dates, overlap with the person's other journeys,
 * LTC block entitlement (config/registrar.php). Fields: kind is fixed by the
 * validator key; from_date, to_date, destination, advance_requested.
 */
class TravelAuthorisationValidator implements SubmissionValidator
{
    public function __construct(private string $kind = 'tour') {}

    public function label(): string
    {
        return $this->kind === 'ltc' ? 'LTC: block entitlement and dates' : 'Tour: dates and overlap';
    }

    public function provides(): array
    {
        return ['days', 'block'];
    }

    public function validate(array $data, User $initiator, WorkflowInstance $instance): array
    {
        $from = CarbonImmutable::parse($data['from_date']);
        $to = CarbonImmutable::parse($data['to_date']);
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to_date' => 'Return cannot be before departure.']);
        }
        $clash = TravelAuthorisation::query()->where('user_id', $initiator->id)->whereIn('status', ['pending', 'approved'])
            ->where('workflow_instance_id', '!=', $instance->id)->where('from_date', '<=', $to)->where('to_date', '>=', $from)->first();
        if ($clash) {
            throw ValidationException::withMessages(['from_date' => "Overlaps your {$clash->kind} to {$clash->destination} ({$clash->from_date->toDateString()} – {$clash->to_date->toDateString()})."]);
        }

        $extra = ['days' => $from->diffInDays($to) + 1];
        if ($this->kind === 'ltc') {
            $block = collect(config('registrar.ltc.blocks'))->search(fn ($range) => $from->between($range[0], $range[1]));
            if ($block === false) {
                throw ValidationException::withMessages(['from_date' => 'No LTC block is configured for these dates.']);
            }
            $used = TravelAuthorisation::query()->where(['user_id' => $initiator->id, 'kind' => 'ltc', 'block' => $block])
                ->whereIn('status', ['pending', 'approved'])->where('workflow_instance_id', '!=', $instance->id)->count();
            if ($used >= config('registrar.ltc.per_block')) {
                throw ValidationException::withMessages(['from_date' => "You have already used your LTC for block {$block}."]);
            }
            $extra['block'] = $block;
        }

        return $extra;
    }
}
