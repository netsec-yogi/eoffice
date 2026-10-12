<?php

namespace Tests\Integration;

use App\Modules\ComputerCentre\Integrations\LdapGroupProvisioner;
use App\Modules\Identity\Directory\DirectoryUnavailable;
use App\Modules\Identity\Directory\LdapDirectoryService;
use App\Modules\Identity\Models\User;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Runs against the development OpenLDAP container (docker/ldap). Skipped
 * when it is not reachable. Uses only the synthetic directory entries.
 */
#[Group('ldap')]
class LdapDirectoryTest extends TestCase
{
    private LdapDirectoryService $ldap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ldap = new LdapDirectoryService;
        if (! $this->ldap->ping()) {
            $this->markTestSkipped('Development directory not reachable.');
        }
    }

    public function test_real_bind_authentication_over_starttls(): void
    {
        $user = $this->ldap->authenticate('asharma', 'Dev-Password-2026');
        $this->assertSame('asharma', $user?->username);
        $this->assertSame('faculty', $user->category);
        $this->assertNull($this->ldap->authenticate('asharma', 'wrong'));
        $this->assertNull($this->ldap->authenticate('asharma', ''));
        $this->assertNull($this->ldap->authenticate('*)(uid=*', 'x'));
        $this->assertTrue($this->ldap->find('disabled1')->disabled);
    }

    public function test_certificate_validation_cannot_be_skipped(): void
    {
        config(['directory.connection.ca_cert' => '/etc/ssl/certs/ca-certificates.crt']);
        $this->expectException(DirectoryUnavailable::class);
        $this->ldap->find('asharma');
    }

    public function test_group_provisioning_is_idempotent(): void
    {
        config([
            'computer_centre.network.groups.vpn' => 'cn=vpn-users,ou=groups,dc=institute,dc=test',
            'computer_centre.network.bind_dn' => 'cn=admin,dc=institute,dc=test',
            'computer_centre.network.bind_password' => env('LDAP_DEV_ADMIN_PASSWORD', 'dev-admin-secret'),
        ]);
        $user = User::factory()->make()->forceFill(['ldap_dn' => 'uid=pkumar,ou=people,dc=institute,dc=test']);
        $p = new LdapGroupProvisioner;

        $this->assertTrue($p->grant($user, 'vpn')->details['changed']);
        $this->assertFalse($p->grant($user, 'vpn')->details['changed']);
        $this->assertTrue($p->revoke($user, 'vpn')->details['changed']);
        $this->assertFalse($p->revoke($user, 'vpn')->details['changed']);
    }
}
