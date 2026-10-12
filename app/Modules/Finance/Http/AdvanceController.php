<?php

namespace App\Modules\Finance\Http;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Advance;
use App\Modules\Identity\Permissions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Advances: one's own, and the outstanding / overdue register for Finance (FIN-001). */
class AdvanceController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $register = $user->can(Permissions::BUDGET_VIEW);
        $f = $request->validate(['status' => ['nullable', Rule::in(['requested', 'sanctioned', 'disbursed', 'settled', 'rejected', 'cancelled', 'overdue'])], 'all' => ['nullable', 'boolean']]);
        $all = $register && ($f['all'] ?? false);
        $base = fn () => Advance::query()->when(! $all, fn ($q) => $q->where('user_id', $user->id));

        return Inertia::render('Advances/Index', [
            'register' => $register,
            'filters' => $f,
            'advances' => $base()->with('user:id,name')
                ->when(($f['status'] ?? null) === 'overdue', fn ($q) => $q->where('status', 'disbursed')->whereDate('settle_by', '<', today()))
                ->when(($f['status'] ?? null) && $f['status'] !== 'overdue', fn ($q) => $q->where('status', $f['status']))
                ->latest()->limit(300)->get()
                ->map(fn (Advance $a) => [...$a->only('id', 'purpose', 'amount_requested', 'amount_sanctioned', 'status', 'disbursement_reference', 'spent', 'balance', 'workflow_instance_id', 'settlement_instance_id'),
                    'settle_by' => $a->settle_by?->toDateString(), 'overdue' => $a->isOverdue(), 'user' => $a->user->name]),
            'totals' => $all ? [
                'outstanding' => (float) Advance::query()->where('status', 'disbursed')->sum('amount_sanctioned'),
                'overdue' => (float) Advance::query()->where('status', 'disbursed')->whereDate('settle_by', '<', today())->sum('amount_sanctioned'),
                'overdue_count' => Advance::query()->where('status', 'disbursed')->whereDate('settle_by', '<', today())->count(),
            ] : null,
        ]);
    }
}
