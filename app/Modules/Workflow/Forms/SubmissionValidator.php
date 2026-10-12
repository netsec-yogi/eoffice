<?php

namespace App\Modules\Workflow\Forms;

use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Models\WorkflowInstance;

/**
 * Business validation a departmental module adds to a workflow's form
 * (leave balance, overlaps, eligibility ...). Selected per workflow version
 * (`settings.validator`), registered in config/workflow.php `validators`.
 * Runs on the server after the form's own rules, at submission and
 * resubmission. Throw ValidationException (keyed by field) to refuse;
 * return values to add to the stored data (e.g. computed `days`).
 */
interface SubmissionValidator
{
    public function label(): string;

    /** @return list<string> keys this validator adds to the data (usable in conditions and deadlines) */
    public function provides(): array;

    /** @return array<string, mixed> computed values merged into the data */
    public function validate(array $data, User $initiator, WorkflowInstance $instance): array;
}
