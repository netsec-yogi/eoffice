<?php

namespace App\Modules\ComputerCentre\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\ComputerCentre\Models\ItAsset;
use App\Modules\ComputerCentre\Services\AssetService;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AssetController extends Controller
{
    public function __construct(private AssetService $assets) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permissions::CC_MANAGE);
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(ItAsset::STATUSES)],
            'category' => ['nullable', 'string', 'max:64'],
            'warranty' => ['nullable', Rule::in(['expiring', 'expired'])],
        ]);

        $assets = ItAsset::query()->with('custodian:id,name,username')
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($q) => $q->where('asset_number', 'ilike', "%{$t}%")->orWhere('serial_number', 'ilike', "%{$t}%")->orWhere('model', 'ilike', "%{$t}%")
                ->orWhereHas('custodian', fn ($u) => $u->where('name', 'ilike', "%{$t}%"))))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when(($f['warranty'] ?? null) === 'expiring', fn ($q) => $q->whereBetween('warranty_until', [today(), today()->addDays(60)]))
            ->when(($f['warranty'] ?? null) === 'expired', fn ($q) => $q->where('warranty_until', '<', today())->where('status', '!=', 'disposed'))
            ->orderBy('asset_number')->paginate(25)->withQueryString()
            ->through(fn (ItAsset $a) => [
                ...$a->only('id', 'asset_number', 'category', 'manufacturer', 'model', 'serial_number', 'location', 'status'),
                'warranty_until' => $a->warranty_until?->toDateString(),
                'custodian' => $a->custodian?->only('id', 'name', 'username'),
            ]);

        return Inertia::render('ComputerCentre/Assets/Index', [
            'assets' => $assets,
            'filters' => $f,
            'categories' => config('computer_centre.asset_categories'),
            'counts' => ItAsset::query()->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(ItAsset $asset): Response
    {
        $this->authorize(Permissions::CC_MANAGE);
        $asset->load(['custodian:id,name,username', 'movements.from:id,name', 'movements.to:id,name', 'movements.by:id,name']);

        return Inertia::render('ComputerCentre/Assets/Show', [
            'asset' => [
                ...$asset->only('id', 'asset_number', 'category', 'manufacturer', 'model', 'serial_number', 'purchase_reference', 'location', 'status', 'notes'),
                'cost' => $asset->cost,
                'purchase_date' => $asset->purchase_date?->toDateString(),
                'warranty_until' => $asset->warranty_until?->toDateString(),
                'custodian' => $asset->custodian?->only('id', 'name', 'username'),
                'movements' => $asset->movements->map(fn ($m) => [
                    'id' => $m->id, 'action' => $m->action, 'from' => $m->from?->name, 'to' => $m->to?->name, 'by' => $m->by?->name ?? 'Workflow automation',
                    'remarks' => $m->remarks, 'workflow_instance_id' => $m->workflow_instance_id, 'at' => $m->created_at,
                ]),
            ],
            'categories' => config('computer_centre.asset_categories'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::CC_MANAGE);
        $asset = $this->assets->register($this->validated($request), $request->user());

        return redirect()->route('cc.assets.show', $asset)->with('success', "Asset {$asset->asset_number} registered.");
    }

    public function update(Request $request, ItAsset $asset, AuditLogger $audit): RedirectResponse
    {
        $this->authorize(Permissions::CC_MANAGE);
        $asset->fill($this->validated($request, $asset));
        $changes = $asset->getDirty();
        $asset->save();
        $audit->record('cc.asset.updated', $asset, ['changes' => $changes]);

        return back()->with('success', 'Asset updated.');
    }

    public function move(Request $request, ItAsset $asset): RedirectResponse
    {
        $this->authorize(Permissions::CC_MANAGE);
        $data = $request->validate([
            'action' => ['required', Rule::in(['issue', 'return', 'transfer', 'repair', 'repaired', 'dispose'])],
            'to' => ['nullable', 'exists:users,id'],
            'remarks' => [Rule::requiredIf(in_array($request->input('action'), ['dispose', 'repair'], true)), 'nullable', 'string', 'max:2000'],
        ]);
        $this->assets->move($asset, $data['action'], $request->user(), isset($data['to']) ? User::query()->find($data['to']) : null, $data['remarks'] ?? null);

        return back()->with('success', 'Asset updated.');
    }

    private function validated(Request $request, ?ItAsset $asset = null): array
    {
        return $request->validate([
            'asset_number' => ['required', 'string', 'max:64', Rule::unique('it_assets')->ignore($asset)],
            'category' => ['required', Rule::in(config('computer_centre.asset_categories'))],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255', Rule::unique('it_assets')->ignore($asset)],
            'purchase_date' => ['nullable', 'date'],
            'purchase_reference' => ['nullable', 'string', 'max:255'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'warranty_until' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
