<?php

namespace App\Modules\ComputerCentre\Http;

use App\Http\Controllers\Controller;
use App\Modules\ComputerCentre\Models\ItAccount;
use App\Modules\Identity\Permissions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The ledger of granted access (read-only; changes happen through workflows). */
class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permissions::CC_MANAGE);
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'kind' => ['nullable', Rule::in(ItAccount::KINDS)], 'status' => ['nullable', Rule::in(['active', 'suspended', 'deprovisioned'])], 'expiring' => ['nullable', 'boolean']]);

        return Inertia::render('ComputerCentre/Accounts/Index', [
            'accounts' => ItAccount::query()->with('user:id,name,username,category')
                ->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($q) => $q->where('identifier', 'ilike', "%{$t}%")->orWhereHas('user', fn ($u) => $u->where('name', 'ilike', "%{$t}%")->orWhere('username', 'ilike', "%{$t}%"))))
                ->when($f['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
                ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($f['expiring'] ?? false, fn ($q) => $q->where('status', 'active')->whereNotNull('expires_on')->where('expires_on', '<=', today()->addDays(30)))
                ->latest('provisioned_at')->paginate(30)->withQueryString()
                ->through(fn (ItAccount $a) => [
                    ...$a->only('id', 'kind', 'identifier', 'status', 'provisioned_by', 'workflow_instance_id'),
                    'user' => $a->user->only('id', 'name', 'username', 'category'),
                    'expires_on' => $a->expires_on?->toDateString(),
                    'provisioned_at' => $a->provisioned_at->toIso8601String(),
                    'deprovisioned_at' => $a->deprovisioned_at?->toIso8601String(),
                ]),
            'filters' => $f,
            'integrations' => [
                'email' => config('computer_centre.email.driver'),
                'network' => config('computer_centre.network.driver'),
                'vm' => config('computer_centre.vm.driver'),
            ],
        ]);
    }
}
