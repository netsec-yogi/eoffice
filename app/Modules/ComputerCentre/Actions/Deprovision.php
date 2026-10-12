<?php

namespace App\Modules\ComputerCentre\Actions;

use App\Modules\ComputerCentre\Integrations\MailboxProvisioner;
use App\Modules\ComputerCentre\Integrations\NetworkAccessProvisioner;
use App\Modules\ComputerCentre\Integrations\VirtualMachineProvisioner;
use App\Modules\ComputerCentre\Models\ItAccount;
use App\Modules\ComputerCentre\Models\LicenceAllocation;
use App\Modules\ComputerCentre\Services\AccountLedger;
use App\Modules\ComputerCentre\Services\LicenceService;
use App\Modules\Workflow\Automation\AutomationAction;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;

/**
 * Applies an approved deprovisioning decision (ITSM-007) to every active
 * ledger entry of the account holder. Nothing is deleted: network access is
 * revoked, mailboxes suspended (retained per the reviewed decision), VMs
 * stopped, licences released. Re-running is safe: closed entries are
 * skipped. Anything that cannot be automated is listed and the step goes
 * to a person, with the automated part already done.
 *
 * Refuses outright when the review recorded a legal hold.
 */
class Deprovision implements AutomationAction
{
    use ResolvesAccountHolder;

    public function __construct(
        private NetworkAccessProvisioner $network,
        private MailboxProvisioner $mailboxes,
        private VirtualMachineProvisioner $vms,
        private AccountLedger $ledger,
        private LicenceService $licences,
    ) {}

    public function label(): string
    {
        return 'Computer Centre: apply approved deprovisioning';
    }

    public function settings(): array
    {
        return [];
    }

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        if (! empty($instance->data['legal_hold'])) {
            return AutomationResult::failure('A legal hold was recorded; accounts must not be changed.');
        }
        $user = $this->holder($instance);
        $done = [];
        $manual = [];

        foreach (ItAccount::query()->where('user_id', $user->id)->where('status', 'active')->get() as $account) {
            $result = match ($account->kind) {
                'wifi', 'vpn' => $this->network->revoke($user, $account->kind),
                'email' => $this->mailboxes->suspend($user, $account->identifier),
                'vm' => $this->vms->decommission($account->identifier),
                default => null,
            };
            if ($result?->isOk()) {
                $this->ledger->close($account, $account->kind === 'email' ? 'suspended' : 'deprovisioned', $instance->id);
                $done[] = "{$account->kind} {$account->identifier}";
            } else {
                $manual[] = "{$account->kind} {$account->identifier}: ".($result?->message ?? 'no automation');
            }
        }

        foreach (LicenceAllocation::query()->where('user_id', $user->id)->whereNull('revoked_at')->with('title')->get() as $allocation) {
            $this->licences->revoke($allocation, null, "Deprovisioning {$instance->reference}");
            $done[] = "licence {$allocation->title->code}";
        }

        $output = ['deprovisioned' => implode('; ', $done), 'manual_actions' => implode('; ', $manual)];
        if ($manual) {
            return AutomationResult::blocked('Done automatically: '.($done ? implode('; ', $done) : 'nothing').'. Still to do by hand: '.implode('; ', $manual).'.');
        }

        return AutomationResult::success($output, $done ? 'Done: '.implode('; ', $done) : 'No active access found.');
    }
}
