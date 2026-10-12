<?php

namespace App\Modules\ComputerCentre\Integrations;

use App\Modules\Identity\Models\User;

/** Wi-Fi / VPN entitlement. Granting twice or revoking twice must be harmless. */
interface NetworkAccessProvisioner
{
    /** @param 'wifi'|'vpn' $profile */
    public function grant(User $user, string $profile): ProvisionResult;

    public function revoke(User $user, string $profile): ProvisionResult;
}
