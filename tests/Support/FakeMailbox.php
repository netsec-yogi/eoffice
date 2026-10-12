<?php

namespace Tests\Support;

use App\Modules\ComputerCentre\Integrations\MailboxProvisioner;
use App\Modules\ComputerCentre\Integrations\ProvisionResult;
use App\Modules\Identity\Models\User;

/** In-memory mail platform. */
class FakeMailbox implements MailboxProvisioner
{
    /** @var array<string, string> address => state */
    public array $boxes = [];

    public function create(User $user, string $address): ProvisionResult
    {
        $this->boxes[$address] = 'active';

        return ProvisionResult::ok($address);
    }

    public function suspend(User $user, string $address): ProvisionResult
    {
        $this->boxes[$address] = 'suspended';

        return ProvisionResult::ok($address);
    }
}
