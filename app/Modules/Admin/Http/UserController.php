<?php

namespace App\Modules\Admin\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Directory\DirectoryService;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\Designation;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permissions::USERS_VIEW);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'integer'],
            'category' => ['nullable', Rule::in(User::CATEGORIES)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $users = User::query()
            ->with(['department:id,code,name', 'designation:id,name', 'roles:id,name'])
            ->when($this->departmentScope($request->user()), fn ($q, $ids) => $q->whereIn('department_id', $ids))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.mb_strtolower($term).'%';
                $q->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(username) like ?', [$like])
                    ->orWhereRaw('lower(email) like ?', [$like])
                    ->orWhereRaw('lower(employee_id) like ?', [$like]));
            })
            ->when($filters['department'] ?? null, fn ($q, $d) => $q->where('department_id', $d))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->active())
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where(fn ($q) => $q->where('is_active', false)->orWhere('directory_disabled', true)))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'email' => $u->email,
                'employee_id' => $u->employee_id,
                'category' => $u->category,
                'auth_source' => $u->auth_source,
                'department' => $u->department?->code,
                'designation' => $u->designation?->name,
                'roles' => $u->roles->pluck('name'),
                'status' => $u->canSignIn() ? 'active' : ($u->directory_disabled ? 'directory_disabled' : 'inactive'),
                'last_login_at' => $u->last_login_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $filters,
            'departments' => Department::query()->orderBy('name')->get(['id', 'code', 'name']),
            'categories' => User::CATEGORIES,
            'canManage' => $request->user()->can(Permissions::USERS_MANAGE),
        ]);
    }

    public function edit(Request $request, User $user): Response
    {
        $this->authorize(Permissions::USERS_VIEW);
        $this->ensureInScope($request->user(), $user);
        $user->load(['roles:id,name', 'reportsTo:id,name,username', 'authorityAssignments.authority:id,code,name', 'authorityAssignments.department:id,code']);

        return Inertia::render('Admin/Users/Edit', [
            'user' => [
                ...$user->only('id', 'name', 'username', 'email', 'phone', 'employee_id', 'category', 'auth_source', 'department_id', 'designation_id', 'reports_to_id', 'is_active', 'directory_disabled'),
                'reports_to' => $user->reportsTo?->only('id', 'name', 'username'),
                'roles' => $user->roles->pluck('name'),
                'last_synced_at' => $user->last_synced_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'authorities' => $user->authorityAssignments->map(fn ($a) => [
                    'authority' => $a->authority->name,
                    'department' => $a->department?->code,
                    'starts_on' => $a->starts_on->toDateString(),
                    'ends_on' => $a->ends_on?->toDateString(),
                    'is_acting' => $a->is_acting,
                ]),
            ],
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'designations' => Designation::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'categories' => User::CATEGORIES,
            'can' => [
                'manage' => $request->user()->can(Permissions::USERS_MANAGE),
                'roles' => $request->user()->can(Permissions::ROLES_MANAGE),
            ],
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize(Permissions::USERS_MANAGE);
        $data = $request->validate([
            'department_id' => ['nullable', 'exists:departments,id'],
            'designation_id' => ['nullable', 'exists:designations,id'],
            'reports_to_id' => ['nullable', 'exists:users,id', Rule::notIn([$user->id])],
            'category' => ['required', Rule::in(User::CATEGORIES)],
            'phone' => ['nullable', 'string', 'max:32'],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($user->is($request->user()) && ! $data['is_active']) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }
        if ($data['reports_to_id'] && $this->createsReportingCycle($user, (int) $data['reports_to_id'])) {
            return back()->withErrors(['reports_to_id' => 'This would create a reporting cycle.']);
        }

        $before = $user->only(array_keys($data));
        $user->fill(Arr::except($data, 'is_active'));
        $user->forceFill(['is_active' => $data['is_active']]);
        $changes = $user->getDirty();
        $user->save();

        if ($changes !== []) {
            $this->audit->record('admin.user.updated', $user, ['before' => Arr::only($before, array_keys($changes)), 'after' => $changes]);
        }

        return back()->with('success', 'User updated.');
    }

    public function updateRoles(Request $request, User $user): RedirectResponse
    {
        $this->authorize(Permissions::ROLES_MANAGE);
        $data = $request->validate(['roles' => ['array'], 'roles.*' => ['string', 'exists:roles,name']]);
        $roles = collect($data['roles'] ?? [])->unique()->values();

        // Only a system administrator may grant or remove system_admin, and
        // nobody can change their own roles (separation of duties).
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot change your own roles.');
        }
        $touchesAdmin = $roles->contains('system_admin') !== $user->hasRole('system_admin');
        if ($touchesAdmin && ! $request->user()->hasRole('system_admin')) {
            abort(403, 'Only a system administrator can grant or revoke system administration.');
        }

        $before = $user->getRoleNames()->values()->all();
        $user->syncRoles($roles->all());
        $this->audit->record('admin.user.roles_changed', $user, ['before' => $before, 'after' => $roles->all()]);

        return back()->with('success', 'Roles updated.');
    }

    /** Local accounts for people outside the directory (vendors, external members). */
    public function storeLocal(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::USERS_MANAGE);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]+$/', 'unique:users,username'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'category' => ['required', Rule::in(['external', 'vendor'])],
            'password' => ['required', Password::min(12)->mixedCase()->numbers()->uncompromised()],
        ]);

        // The directory must not already know this login name.
        if (rescue(fn () => app(DirectoryService::class)->find($data['username']), null, false)) {
            return back()->withErrors(['username' => 'This username exists in the institutional directory.']);
        }

        $user = (new User)->fill(Arr::only($data, ['name', 'email', 'phone', 'category']));
        $user->forceFill(['username' => $data['username'], 'auth_source' => 'local', 'password' => $data['password']])->save();
        $user->assignRole($data['category'] === 'vendor' ? 'vendor' : 'employee');
        $this->audit->record('admin.user.local_created', $user, ['category' => $data['category']]);

        return redirect()->route('admin.users.edit', $user)->with('success', 'Local account created.');
    }

    /** Department administrators see only their own department tree. */
    private function departmentScope(User $actor): ?array
    {
        if ($actor->can(Permissions::USERS_MANAGE) || ! $actor->hasRole('department_admin')) {
            return null;
        }

        return $actor->department ? $actor->department->descendantIds() : [0];
    }

    private function ensureInScope(User $actor, User $target): void
    {
        $scope = $this->departmentScope($actor);
        abort_if($scope !== null && ! in_array($target->department_id, $scope, true), 403);
    }

    private function createsReportingCycle(User $user, int $managerId): bool
    {
        $seen = [$user->id];
        $current = $managerId;
        while ($current) {
            if (in_array($current, $seen, true)) {
                return true;
            }
            $seen[] = $current;
            $current = User::query()->whereKey($current)->value('reports_to_id');
        }

        return false;
    }
}
