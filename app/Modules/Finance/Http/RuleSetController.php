<?php

namespace App\Modules\Finance\Http;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\ProcurementRuleSet;
use App\Modules\Finance\Services\ProcurementRules;
use App\Modules\Identity\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Versioned procurement rules (PROC-002): drafted and published by Finance. */
class RuleSetController extends Controller
{
    public function __construct(private ProcurementRules $rules) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can(Permissions::PROCUREMENT_RULES) || $user->can(Permissions::PROCUREMENT_MANAGE) || $user->can(Permissions::BUDGET_VIEW), 403);

        return Inertia::render('Procurement/Rules', [
            'sets' => ProcurementRuleSet::query()->with('publisher:id,name')->orderByDesc('version')->get()
                ->map(fn ($s) => [...$s->only('id', 'version', 'status', 'title', 'authority', 'bands'), 'effective_from' => $s->effective_from?->toDateString(), 'published_at' => $s->published_at?->toIso8601String(), 'published_by' => $s->publisher?->name]),
            'methods' => collect(ProcurementRules::METHODS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'canEdit' => $user->can(Permissions::PROCUREMENT_RULES),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::PROCUREMENT_RULES);
        $draft = ProcurementRuleSet::query()->where('status', 'draft')->first();
        $this->rules->saveDraft($draft, $this->validated($request), $request->user());

        return back()->with('success', 'Draft saved.');
    }

    public function publish(Request $request, ProcurementRuleSet $set): RedirectResponse
    {
        $this->authorize(Permissions::PROCUREMENT_RULES);
        $request->validate(['confirm' => ['accepted']]);
        $this->rules->publish($set, $request->user());

        return back()->with('success', "Rule set version {$set->version} is now in force for new indents.");
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'authority' => ['required', 'string', 'max:2000'],
            'effective_from' => ['nullable', 'date'],
            'bands' => ['required', 'array', 'min:1', 'max:20'],
            'bands.*.up_to' => ['nullable', 'numeric', 'min:0'],
            'bands.*.method' => ['required', Rule::in(array_keys(ProcurementRules::METHODS))],
            'bands.*.label' => ['required', 'string', 'max:255'],
            'bands.*.quotations' => ['required', 'integer', 'min:0', 'max:50'],
            'bands.*.committee' => ['boolean'],
        ]);
        $data['bands'] = array_map(fn ($b) => [
            'up_to' => isset($b['up_to']) && $b['up_to'] !== '' ? round((float) $b['up_to'], 2) : null, 'method' => $b['method'], 'label' => $b['label'],
            'quotations' => (int) $b['quotations'], 'committee' => (bool) ($b['committee'] ?? false),
        ], array_values($data['bands']));

        return $data;
    }
}
