<?php

namespace App\Modules\Registrar\Validators;

use App\Modules\Identity\Models\User;
use App\Modules\Registrar\Models\LeaveRecord;
use App\Modules\Registrar\Services\LeaveService;
use App\Modules\Workflow\Forms\SubmissionValidator;
use App\Modules\Workflow\Models\WorkflowInstance;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Cancelling or curtailing approved leave. Fields: leave (record id via
 * option source my_leaves), new_to_date (empty = cancel the whole leave).
 * Adds days_restored.
 */
class LeaveCancellation implements SubmissionValidator
{
    public function __construct(private LeaveService $leave) {}

    public function label(): string
    {
        return 'Leave: cancellation / curtailment';
    }

    public function provides(): array
    {
        return ['days_restored', 'leave_summary'];
    }

    public function validate(array $data, User $initiator, WorkflowInstance $instance): array
    {
        $record = LeaveRecord::query()->with('type')->where('user_id', $initiator->id)->whereIn('status', ['approved', 'curtailed'])->find($data['leave'] ?? 0)
            ?? throw ValidationException::withMessages(['leave' => 'Choose one of your approved leaves.']);
        $newTo = $data['new_to_date'] ?? null;
        if ($newTo) {
            $date = CarbonImmutable::parse($newTo);
            if ($date->lt($record->from_date) || $date->gte($record->to_date)) {
                throw ValidationException::withMessages(['new_to_date' => 'The new end date must fall within the leave and before its current end.']);
            }
            $remaining = $this->leave->days($record->type, $record->from_date->toDateString(), $newTo);
        } else {
            $remaining = 0.0;
        }

        return ['days_restored' => max(0, $record->days - $remaining), 'leave_summary' => "{$record->type->name} {$record->from_date->toDateString()} – {$record->to_date->toDateString()}"];
    }
}
