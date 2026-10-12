<?php

namespace App\Modules\ComputerCentre\Integrations;

use App\Modules\Identity\Models\User;

/** Creates and suspends institutional mailboxes. Implementations must be idempotent. */
interface MailboxProvisioner
{
    public function create(User $user, string $address): ProvisionResult;

    public function suspend(User $user, string $address): ProvisionResult;
}
