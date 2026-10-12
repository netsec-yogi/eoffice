<?php

namespace App\Modules\ComputerCentre\Actions;

use App\Modules\ComputerCentre\Integrations\ProvisionResult;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Models\WorkflowInstance;

trait ResolvesAccountHolder
{
    /** The person the access is for: the form's account_holder field, else the initiator. */
    protected function holder(WorkflowInstance $instance): User
    {
        $id = $instance->data['account_holder'] ?? null;

        return User::query()->findOrFail($id ?: $instance->initiator_id);
    }

    protected function toResult(ProvisionResult $r, array $output = []): AutomationResult
    {
        return match ($r->status) {
            'ok' => AutomationResult::success($output, $r->message),
            'not_configured' => AutomationResult::blocked($r->message ?? 'Not configured.'),
            default => AutomationResult::failure($r->message ?? 'Failed.'),
        };
    }
}
