<?php

namespace App\Modules\ComputerCentre\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Proxmox VE API (api2/json) with an API token: clone a template, set
 * cores/memory, resize the disk. The VM is named after the request's
 * idempotency key and an existing VM of that name is reused, so a retried
 * job never creates a second machine.
 *
 * Status: implemented against the documented API and tested with faked
 * responses (ProxmoxProvisionerTest). It has NOT been run against a real
 * cluster; verify in a test pool before enabling (docs/INTEGRATIONS.md).
 */
class ProxmoxProvisioner implements VirtualMachineProvisioner
{
    public function provision(array $spec): ProvisionResult
    {
        $c = config('computer_centre.vm.proxmox');
        if (! $c['url'] || ! $c['token_id'] || ! $c['token_secret'] || ! $c['node']) {
            return ProvisionResult::notConfigured('Proxmox');
        }
        $template = $c['templates'][$spec['os']] ?? null;
        if (! $template) {
            return ProvisionResult::failed("No Proxmox template is configured for '{$spec['os']}'.");
        }
        $name = 'wf-'.substr(hash('sha256', $spec['idempotency_key']), 0, 16);

        try {
            $existing = collect($this->http()->get("/nodes/{$c['node']}/qemu")->throw()->json('data', []))->firstWhere('name', $name);
            if ($existing) {
                return ProvisionResult::ok((string) $existing['vmid'], ['name' => $name, 'reused' => true]);
            }

            $vmid = (string) $this->http()->get('/cluster/nextid')->throw()->json('data');
            $this->http()->asForm()->post("/nodes/{$c['node']}/qemu/{$template}/clone", ['newid' => $vmid, 'name' => $name, 'full' => 1])->throw();
            $this->http()->asForm()->post("/nodes/{$c['node']}/qemu/{$vmid}/config", ['cores' => $spec['cpu'], 'memory' => $spec['ram_gb'] * 1024, 'description' => $spec['name']])->throw();
            $this->http()->asForm()->put("/nodes/{$c['node']}/qemu/{$vmid}/resize", ['disk' => 'scsi0', 'size' => "{$spec['disk_gb']}G"])->throw();

            return ProvisionResult::ok($vmid, ['name' => $name, 'node' => $c['node']]);
        } catch (ConnectionException $e) {
            throw $e; // transient: let the job retry
        } catch (RequestException $e) {
            return ProvisionResult::failed('Proxmox refused the request: '.$e->response->status().' '.mb_substr((string) $e->response->body(), 0, 300));
        }
    }

    public function decommission(string $vmId): ProvisionResult
    {
        $c = config('computer_centre.vm.proxmox');
        if (! $c['url'] || ! $c['token_id']) {
            return ProvisionResult::notConfigured('Proxmox');
        }
        $status = $this->http()->get("/nodes/{$c['node']}/qemu/{$vmId}/status/current");
        if ($status->status() === 500 || $status->status() === 404) {
            return ProvisionResult::ok($vmId, ['already_absent' => true]);
        }
        if ($status->json('data.status') === 'running') {
            $this->http()->asForm()->post("/nodes/{$c['node']}/qemu/{$vmId}/status/stop")->throw();
        }

        // Stopped, not destroyed: deletion stays a deliberate manual step.
        return ProvisionResult::ok($vmId, ['stopped' => true]);
    }

    private function http(): PendingRequest
    {
        $c = config('computer_centre.vm.proxmox');

        return Http::baseUrl(rtrim($c['url'], '/').'/api2/json')
            ->withHeaders(['Authorization' => "PVEAPIToken={$c['token_id']}={$c['token_secret']}"])
            ->withOptions(['verify' => $c['ca_bundle'] ?: true])
            ->timeout($c['timeout'])
            ->acceptJson();
    }
}
