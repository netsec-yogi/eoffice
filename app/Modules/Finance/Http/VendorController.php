<?php

namespace App\Modules\Finance\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Finance\Models\Vendor;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The vendor register (VEND-001), kept by the Purchase section. */
class VendorController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permissions::PROCUREMENT_MANAGE);
        $q = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? null;

        return Inertia::render('Vendors/Index', [
            'q' => $q,
            'vendors' => Vendor::query()->with('user:id,username,is_active')
                ->when($q, fn ($b) => $b->where(fn ($b) => $b->where('name', 'ilike', "%{$q}%")->orWhere('code', 'ilike', "%{$q}%")->orWhere('gstin', 'ilike', "%{$q}%")))
                ->orderBy('name')->limit(300)->get()
                ->map(fn (Vendor $v) => [...$v->only('id', 'code', 'name', 'gstin', 'pan', 'address', 'email', 'phone', 'ifsc', 'msme', 'is_active'),
                    'account' => $v->maskedAccount(), 'portal' => $v->user ? ['username' => $v->user->username, 'active' => $v->user->is_active] : null]),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize(Permissions::PROCUREMENT_MANAGE);
        $vendor = Vendor::query()->create($this->validated($request));
        $audit->record('vendor.created', $vendor, ['code' => $vendor->code], actor: $request->user());

        return back()->with('success', "Vendor {$vendor->code} added.");
    }

    public function update(Request $request, Vendor $vendor, AuditLogger $audit): RedirectResponse
    {
        $this->authorize(Permissions::PROCUREMENT_MANAGE);
        $data = $this->validated($request, $vendor);
        if (($data['bank_account'] ?? '') === '') {
            unset($data['bank_account']); // blank = keep the stored account
        }
        $vendor->fill($data);
        $changed = array_keys($vendor->getDirty());
        $vendor->save();
        $vendor->user?->forceFill(['is_active' => $vendor->is_active, 'name' => $vendor->name])->save();
        $audit->record('vendor.updated', $vendor, ['changed' => $changed], actor: $request->user()); // field names only: never the account number

        return back()->with('success', 'Vendor updated.');
    }

    /**
     * A local sign-in for the vendor portal. The temporary password is shown
     * once to the officer, who hands it over; it is not stored or logged.
     */
    public function account(Request $request, Vendor $vendor, AuditLogger $audit): RedirectResponse
    {
        $this->authorize(Permissions::PROCUREMENT_MANAGE);
        abort_if(! $vendor->email, 422, 'Record the vendor\'s e-mail address first.');
        $password = Str::password(16, symbols: false);
        DB::transaction(function () use ($vendor, $password, $request, $audit) {
            $user = $vendor->user ?? new User;
            if (! $user->exists) {
                $username = 'v-'.Str::lower($vendor->code);
                abort_if(User::query()->where('username', $username)->orWhere('email', $vendor->email)->exists(), 422, 'An account with this username or e-mail already exists.');
                $user->forceFill(['username' => $username, 'email' => $vendor->email, 'category' => 'vendor', 'auth_source' => 'local']);
            }
            $user->forceFill(['name' => $vendor->name, 'password' => $password, 'is_active' => true])->save();
            $vendor->forceFill(['user_id' => $user->id])->save();
            $audit->record($vendor->wasChanged('user_id') ? 'vendor.portal_account_created' : 'vendor.portal_password_reset', $vendor, ['username' => $user->username], actor: $request->user());
        });

        return back()->with('success', "Portal sign-in for {$vendor->name}: username {$vendor->fresh()->user->username}, temporary password {$password}. Shown once — hand it over securely.");
    }

    private function validated(Request $request, ?Vendor $vendor = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:32', 'alpha_dash', Rule::unique('vendors', 'code')->ignore($vendor)],
            'name' => ['required', 'string', 'max:255'],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:/^[0-9]{2}[A-Z0-9]{13}$/', Rule::unique('vendors', 'gstin')->ignore($vendor)],
            'pan' => ['nullable', 'string', 'size:10', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'address' => ['nullable', 'string', 'max:1000'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'bank_account' => ['nullable', 'string', 'regex:/^[0-9]{6,20}$/'],
            'ifsc' => ['nullable', 'string', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'msme' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }
}
