<?php

namespace App\Modules\Admin\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Roles and their permissions (IAM-005). system_admin is fixed. */
class RoleController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize(Permissions::ROLES_MANAGE);

        return Inertia::render('Admin/Roles/Index', [
            'roles' => Role::query()->withCount('users')->with('permissions:id,name')->orderBy('name')->get()
                ->map(fn (Role $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'users_count' => $r->users_count,
                    'permissions' => $r->permissions->pluck('name'),
                    'locked' => $r->name === 'system_admin',
                ]),
            'permissions' => Permission::query()->orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::ROLES_MANAGE);
        $data = $request->validate(['name' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]+$/', 'unique:roles,name']]);
        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $this->audit->record('admin.role.created', $role, $data);

        return back()->with('success', 'Role created.');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorize(Permissions::ROLES_MANAGE);
        abort_if($role->name === 'system_admin', 403, 'The system administrator role always has every permission.');
        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $before = $role->permissions()->pluck('name')->all();
        $role->syncPermissions($data['permissions'] ?? []);
        $this->audit->record('admin.role.permissions_changed', $role, ['before' => $before, 'after' => $data['permissions'] ?? []]);

        return back()->with('success', 'Permissions updated.');
    }
}
