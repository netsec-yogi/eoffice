<?php

namespace App\Modules\ComputerCentre\Integrations;

use App\Modules\Identity\Models\User;

/**
 * No mail-platform adapter exists until the institute's platform and its
 * API are confirmed. Every call reports "not configured" so the workflow
 * routes the step to a person.
 */
class NotConfiguredMailbox implements MailboxProvisioner
{
    public function create(User $user, string $address): ProvisionResult
    {
        return ProvisionResult::notConfigured('Mailbox provisioning');
    }

    public function suspend(User $user, string $address): ProvisionResult
    {
        return ProvisionResult::notConfigured('Mailbox suspension');
    }
}
