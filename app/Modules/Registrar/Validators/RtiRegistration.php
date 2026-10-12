<?php

namespace App\Modules\Registrar\Validators;

use App\Modules\Identity\Models\User;
use App\Modules\Registrar\Models\RtiRequest;
use App\Modules\Workflow\Forms\SubmissionValidator;
use App\Modules\Workflow\Models\WorkflowInstance;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Computes the statutory reply date from config/registrar.php rti (to be
 * verified against the Act): received_on + reply days, or the life/liberty
 * limit, plus the APIO extension. For first appeals (mode "appeal") it
 * checks the RTI exists and computes the appeal decision date.
 */
class RtiRegistration implements SubmissionValidator
{
    public function __construct(private string $mode = 'request') {}

    public function label(): string
    {
        return $this->mode === 'appeal' ? 'RTI: first appeal deadline' : 'RTI: statutory reply date';
    }

    public function provides(): array
    {
        return ['due_on', 'transfer_by', 'appeal_due_on', 'rti_number'];
    }

    public function validate(array $data, User $initiator, WorkflowInstance $instance): array
    {
        $rules = config('registrar.rti');
        if ($this->mode === 'appeal') {
            $rti = RtiRequest::query()->find($data['rti'] ?? 0) ?? throw ValidationException::withMessages(['rti' => 'Choose the RTI application appealed against.']);
            $received = CarbonImmutable::parse($data['appeal_received_on']);
            if ($received->lt($rti->received_on)) {
                throw ValidationException::withMessages(['appeal_received_on' => 'The appeal cannot predate the application.']);
            }

            return ['appeal_due_on' => $received->addDays($rules['appeal_days'])->toDateString(), 'rti_number' => $rti->registration_number];
        }

        $received = CarbonImmutable::parse($data['received_on']);
        if ($received->isFuture()) {
            throw ValidationException::withMessages(['received_on' => 'The receipt date cannot be in the future.']);
        }
        $days = ! empty($data['life_liberty']) ? $rules['life_liberty_days'] : $rules['reply_days'];
        if (($data['received_via'] ?? '') === 'apio') {
            $days += $rules['apio_extra_days'];
        }

        return ['due_on' => $received->addDays($days)->toDateString(), 'transfer_by' => $received->addDays($rules['transfer_days'])->toDateString()];
    }
}
