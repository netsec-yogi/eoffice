<?php

namespace App\Modules\ComputerCentre\Actions;

use App\Modules\ComputerCentre\Integrations\MailboxProvisioner;
use App\Modules\ComputerCentre\Services\AccountLedger;
use App\Modules\Workflow\Automation\AutomationAction;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;

class ProvisionEmail implements AutomationAction
{
    use ResolvesAccountHolder;

    public function __construct(private MailboxProvisioner $mailboxes, private AccountLedger $ledger) {}

    public function label(): string
    {
        return 'Computer Centre: create mailbox';
    }

    public function settings(): array
    {
        return [];
    }

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        $user = $this->holder($instance);
        $address = strtolower(trim((string) ($instance->data['preferred_address'] ?? ''))) ?: $user->username;
        if (! str_contains($address, '@')) {
            $address .= '@'.config('computer_centre.email.domain');
        }

        $result = $this->mailboxes->create($user, $address);
        if ($result->isOk()) {
            $this->ledger->record($user, 'email', $address, $result->details, $instance->id);
        }

        return $this->toResult($result, ['email_address' => $address]);
    }
}
