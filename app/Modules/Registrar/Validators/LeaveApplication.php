<?php

namespace App\Modules\Registrar\Validators;

use App\Modules\Identity\Models\User;
use App\Modules\Registrar\Models\LeaveType;
use App\Modules\Registrar\Services\LeaveService;
use App\Modules\Workflow\Forms\SubmissionValidator;
use App\Modules\Workflow\Models\WorkflowInstance;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Leave rules from the leave-type configuration: eligibility, notice,
 * maximum per application, overlap, balance, supporting certificate.
 * Expects form fields leave_type (code), from_date, to_date, half_day,
 * certificate (file). Adds `days` and `leave_type_name`.
 */
class LeaveApplication implements SubmissionValidator
{
    public function __construct(private LeaveService $leave) {}

    public function label(): string
    {
        return 'Leave: eligibility, overlap and balance';
    }

    public function provides(): array
    {
        return ['days', 'leave_type_name'];
    }

    public function validate(array $data, User $initiator, WorkflowInstance $instance): array
    {
        $type = LeaveType::query()->where('code', $data['leave_type'] ?? '')->where('is_active', true)->first();
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        if (! $type || ! $type->eligible($initiator->category)) {
            $fail('leave_type', 'You are not eligible for this kind of leave.');
        }
        $from = CarbonImmutable::parse($data['from_date']);
        $to = CarbonImmutable::parse($data['to_date']);
        if ($to->lt($from)) {
            $fail('to_date', 'The leave cannot end before it starts.');
        }
        $half = (bool) ($data['half_day'] ?? false);
        if ($half && (! $type->allow_half_day || ! $from->equalTo($to))) {
            $fail('half_day', 'Half a day is possible only for a single day of a type that allows it.');
        }
        if ($type->min_notice_days !== null && $from->lt(today()->addDays($type->min_notice_days)) && ! $instance->revision) {
            $fail('from_date', "Apply at least {$type->min_notice_days} day(s) in advance for {$type->name}.");
        }

        $days = $this->leave->days($type, $from->toDateString(), $to->toDateString(), $half);
        if ($days <= 0) {
            $fail('to_date', 'The period contains no working days.');
        }
        if ($type->max_days_per_application !== null && $days > $type->max_days_per_application) {
            $fail('to_date', "At most {$type->max_days_per_application} day(s) of {$type->name} per application.");
        }
        if ($clash = $this->leave->overlapping($initiator, $from->toDateString(), $to->toDateString(), $instance->id)) {
            $fail('from_date', "Overlaps {$clash->type->name} from {$clash->from_date->toDateString()} to {$clash->to_date->toDateString()}.");
        }
        if ($type->has_balance && ($available = $this->leave->available($initiator, $type, $instance->id)) < $days) {
            $fail('leave_type', "Not enough {$type->name}: {$days} day(s) asked, {$available} available.");
        }
        if ($type->certificate_after_days !== null && $days > $type->certificate_after_days && empty($data['certificate'])) {
            $fail('certificate', "A supporting certificate is needed for more than {$type->certificate_after_days} day(s).");
        }

        return ['days' => $days, 'leave_type_name' => $type->name];
    }
}
