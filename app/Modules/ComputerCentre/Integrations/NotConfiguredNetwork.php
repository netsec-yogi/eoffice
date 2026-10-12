<?php

namespace App\Modules\ComputerCentre\Integrations;

use App\Modules\Identity\Models\User;

class NotConfiguredNetwork implements NetworkAccessProvisioner
{
    public function grant(User $user, string $profile): ProvisionResult
    {
        return ProvisionResult::notConfigured(strtoupper($profile).' access provisioning');
    }

    public function revoke(User $user, string $profile): ProvisionResult
    {
        return ProvisionResult::notConfigured(strtoupper($profile).' access revocation');
    }
}
