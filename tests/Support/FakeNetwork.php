<?php

namespace Tests\Support;

use App\Modules\ComputerCentre\Integrations\NetworkAccessProvisioner;
use App\Modules\ComputerCentre\Integrations\ProvisionResult;
use App\Modules\Identity\Models\User;

/** In-memory stand-ins for directory groups and the mail platform. */
class FakeNetwork implements NetworkAccessProvisioner
{
    /** @var array<string, list<int>> profile => user ids */
    public array $members = ['wifi' => [], 'vpn' => []];

    public int $calls = 0;

    public function grant(User $user, string $profile): ProvisionResult
    {
        $this->calls++;
        $this->members[$profile] = array_values(array_unique([...$this->members[$profile], $user->id]));

        return ProvisionResult::ok("cn={$profile}-users,ou=groups,dc=test");
    }

    public function revoke(User $user, string $profile): ProvisionResult
    {
        $this->calls++;
        $this->members[$profile] = array_values(array_diff($this->members[$profile], [$user->id]));

        return ProvisionResult::ok("cn={$profile}-users,ou=groups,dc=test");
    }
}
