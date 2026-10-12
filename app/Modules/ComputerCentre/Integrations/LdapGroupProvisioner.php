<?php

namespace App\Modules\ComputerCentre\Integrations;

use App\Modules\Identity\Models\User;
use LdapRecord\Connection;
use LdapRecord\LdapRecordException;

/**
 * Grants Wi-Fi/VPN by adding the user's DN to a groupOfNames that
 * FreeRADIUS (or the VPN) authorises against. Uses a dedicated provisioning
 * account, over the same validated TLS as sign-in. Idempotent: adding an
 * existing member or removing a non-member succeeds without change.
 */
class LdapGroupProvisioner implements NetworkAccessProvisioner
{
    public function grant(User $user, string $profile): ProvisionResult
    {
        return $this->modify($user, $profile, add: true);
    }

    public function revoke(User $user, string $profile): ProvisionResult
    {
        return $this->modify($user, $profile, add: false);
    }

    private function modify(User $user, string $profile, bool $add): ProvisionResult
    {
        $group = config("computer_centre.network.groups.{$profile}");
        if (! $group || ! config('computer_centre.network.bind_dn')) {
            return ProvisionResult::notConfigured(strtoupper($profile).' group');
        }
        if (! $user->ldap_dn) {
            return ProvisionResult::failed('The user has no directory entry.');
        }

        $connection = $this->connection();
        try {
            $connection->connect();
            $ldap = $connection->getLdapConnection();
            $members = $this->members($ldap, $group);
            $isMember = in_array(strtolower($user->ldap_dn), array_map('strtolower', $members), true);

            if ($add && ! $isMember) {
                $ldap->modAdd($group, ['member' => [$user->ldap_dn]]);
            } elseif (! $add && $isMember) {
                if (count($members) <= 1) {
                    return ProvisionResult::failed('The group would become empty; groupOfNames needs a member. Add a placeholder member to the group.');
                }
                $ldap->modDelete($group, ['member' => [$user->ldap_dn]]);
            }

            return ProvisionResult::ok($group, ['changed' => $add !== $isMember]);
        } catch (LdapRecordException $e) {
            return ProvisionResult::failed('Directory refused the change: '.$e->getMessage());
        } finally {
            $connection->disconnect();
        }
    }

    /** @return list<string> */
    private function members($ldap, string $group): array
    {
        $result = $ldap->read($group, '(objectClass=*)', ['member']);
        $entries = $result ? $ldap->getEntries($result) : [];
        $values = $entries[0]['member'] ?? [];
        unset($values['count']);

        return array_values($values);
    }

    protected function connection(): Connection
    {
        $c = config('directory.connection');
        ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_HARD);
        if (! empty($c['ca_cert'])) {
            ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $c['ca_cert']);
        }

        return new Connection([
            'hosts' => $c['hosts'],
            'port' => $c['port'],
            'base_dn' => $c['base_dn'],
            'username' => config('computer_centre.network.bind_dn'),
            'password' => config('computer_centre.network.bind_password'),
            'use_tls' => $c['use_ssl'],
            'use_starttls' => $c['use_tls'] && ! $c['use_ssl'],
            'timeout' => $c['timeout'],
        ]);
    }
}
