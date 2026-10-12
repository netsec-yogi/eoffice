<?php

namespace App\Modules\Registrar\Http;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Permissions;
use App\Modules\Registrar\Models\ReimbursementClaim;
use App\Modules\Registrar\Models\TravelAuthorisation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Journeys and claims: own records for everyone, the register for finance / establishment. */
class ClaimsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $register = $user->can(Permissions::CLAIMS_VIEW);
        $f = $request->validate(['kind' => ['nullable', Rule::in(['tour', 'ltc', 'medical'])], 'status' => ['nullable', 'string', 'max:16'], 'all' => ['nullable', 'boolean']]);
        $all = $register && ($f['all'] ?? false);

        return Inertia::render('Claims/Index', [
            'register' => $register,
            'filters' => $f,
            'journeys' => TravelAuthorisation::query()->with('user:id,name')->when(! $all, fn ($q) => $q->where('user_id', $user->id))
                ->when(in_array($f['kind'] ?? null, ['tour', 'ltc'], true), fn ($q) => $q->where('kind', $f['kind']))
                ->latest('from_date')->limit(100)->get()
                ->map(fn ($a) => [...$a->only('id', 'kind', 'destination', 'status', 'claim_status', 'advance_requested', 'advance_sanctioned', 'workflow_instance_id'), 'from' => $a->from_date->toDateString(), 'to' => $a->to_date->toDateString(), 'user' => $a->user->name]),
            'claims' => ReimbursementClaim::query()->with('user:id,name')->when(! $all, fn ($q) => $q->where('user_id', $user->id))
                ->when($f['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->latest()->limit(200)->get()
                ->map(fn ($c) => [...$c->only('id', 'kind', 'status', 'claimed_amount', 'admissible_amount', 'advance_adjusted', 'net_payable', 'payment_reference', 'workflow_instance_id'), 'paid_on' => $c->paid_on?->toDateString(), 'user' => $c->user->name, 'created_at' => $c->created_at->toIso8601String()]),
            'totals' => $all ? [
                'pending' => (float) ReimbursementClaim::query()->whereIn('status', ['pending', 'sanctioned'])->sum('claimed_amount'),
                'payable' => (float) ReimbursementClaim::query()->where('status', 'sanctioned')->sum('net_payable'),
                'advances_outstanding' => (float) TravelAuthorisation::query()->where('status', 'approved')->where('claim_status', '!=', 'settled')->sum('advance_sanctioned'),
            ] : null,
        ]);
    }
}
