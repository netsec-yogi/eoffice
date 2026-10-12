<?php

namespace Tests\Feature\ComputerCentre;

use App\Modules\ComputerCentre\Integrations\ProxmoxProvisioner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Proxmox adapter against faked API responses shaped like Proxmox VE's
 * api2/json. This proves request construction and idempotency, not
 * compatibility with a real cluster (docs/INTEGRATIONS.md).
 */
class ProxmoxProvisionerTest extends TestCase
{
    private array $spec = ['name' => 'VM/2026/1 asharma', 'os' => 'ubuntu-24.04', 'cpu' => 2, 'ram_gb' => 4, 'disk_gb' => 40, 'idempotency_key' => 'wf:1:provision:rev1:tok1'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['computer_centre.vm.proxmox' => [
            'url' => 'https://pve.test:8006', 'token_id' => 'workflow@pve!provision', 'token_secret' => 'secret-uuid',
            'node' => 'pve1', 'templates' => ['ubuntu-24.04' => '9000'], 'ca_bundle' => null, 'timeout' => 5,
        ]]);
    }

    public function test_clones_configures_and_resizes_a_new_vm(): void
    {
        Http::fake([
            'pve.test:8006/api2/json/nodes/pve1/qemu' => Http::response(['data' => []]),
            'pve.test:8006/api2/json/cluster/nextid' => Http::response(['data' => '123']),
            'pve.test:8006/api2/json/nodes/pve1/qemu/9000/clone' => Http::response(['data' => 'UPID:pve1:clone']),
            'pve.test:8006/api2/json/nodes/pve1/qemu/123/config' => Http::response(['data' => null]),
            'pve.test:8006/api2/json/nodes/pve1/qemu/123/resize' => Http::response(['data' => null]),
        ]);

        $result = (new ProxmoxProvisioner)->provision($this->spec);

        $this->assertSame(['ok', '123'], [$result->status, $result->identifier]);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'PVEAPIToken=workflow@pve!provision=secret-uuid'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/qemu/9000/clone') && $r['newid'] === '123' && str_starts_with($r['name'], 'wf-'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/qemu/123/config') && $r['cores'] === 2 && $r['memory'] === 4096);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/qemu/123/resize') && $r['size'] === '40G');
    }

    public function test_a_retry_reuses_the_vm_it_already_created(): void
    {
        $name = 'wf-'.substr(hash('sha256', $this->spec['idempotency_key']), 0, 16);
        Http::fake(['pve.test:8006/api2/json/nodes/pve1/qemu' => Http::response(['data' => [['vmid' => 123, 'name' => $name]]])]);

        $result = (new ProxmoxProvisioner)->provision($this->spec);

        $this->assertSame('123', $result->identifier);
        $this->assertTrue($result->details['reused']);
        Http::assertSentCount(1); // no second clone
    }

    public function test_refusals_and_missing_configuration_are_reported(): void
    {
        Http::fake(['*' => Http::response(['errors' => 'permission denied'], 403)]);
        $this->assertSame('failed', (new ProxmoxProvisioner)->provision($this->spec)->status);

        config(['computer_centre.vm.proxmox.token_secret' => null]);
        $this->assertSame('not_configured', (new ProxmoxProvisioner)->provision($this->spec)->status);

        config(['computer_centre.vm.proxmox.token_secret' => 'secret-uuid']);
        $unknownOs = (new ProxmoxProvisioner)->provision([...$this->spec, 'os' => 'windows']);
        $this->assertSame('failed', $unknownOs->status);
        $this->assertStringContainsString('No Proxmox template', $unknownOs->message);
    }
}
