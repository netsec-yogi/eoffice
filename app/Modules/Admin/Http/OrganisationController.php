<?php

namespace App\Modules\Admin\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\Designation;
use App\Modules\Identity\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Departments and designations (IAM-001). */
class OrganisationController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize(Permissions::ORGANISATION_MANAGE);

        return Inertia::render('Admin/Organisation/Index', [
            'departments' => Department::query()->withCount('users')->with('parent:id,code')->orderBy('name')->get()
                ->map(fn (Department $d) => [...$d->only('id', 'code', 'name', 'type', 'parent_id', 'is_active', 'users_count'), 'parent' => $d->parent?->code]),
            'designations' => Designation::query()->orderBy('level')->orderBy('name')->get(['id', 'code', 'name', 'level', 'is_active']),
            'types' => Department::TYPES,
        ]);
    }

    public function storeDepartment(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::ORGANISATION_MANAGE);
        $department = Department::create($this->validateDepartment($request));
        $this->audit->record('admin.department.created', $department, $department->only('code', 'name', 'type', 'parent_id'));

        return back()->with('success', 'Department created.');
    }

    public function updateDepartment(Request $request, Department $department): RedirectResponse
    {
        $this->authorize(Permissions::ORGANISATION_MANAGE);
        $data = $this->validateDepartment($request, $department);
        if ($data['parent_id'] && in_array((int) $data['parent_id'], $department->descendantIds(), true)) {
            return back()->withErrors(['parent_id' => 'A department cannot sit under itself or its own sub-units.']);
        }
        $department->fill($data);
        $changes = $department->getDirty();
        $department->save();
        $this->audit->record('admin.department.updated', $department, ['changes' => $changes]);

        return back()->with('success', 'Department updated.');
    }

    public function storeDesignation(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::ORGANISATION_MANAGE);
        $designation = Designation::create($this->validateDesignation($request));
        $this->audit->record('admin.designation.created', $designation, $designation->only('code', 'name', 'level'));

        return back()->with('success', 'Designation created.');
    }

    public function updateDesignation(Request $request, Designation $designation): RedirectResponse
    {
        $this->authorize(Permissions::ORGANISATION_MANAGE);
        $designation->fill($this->validateDesignation($request, $designation));
        $changes = $designation->getDirty();
        $designation->save();
        $this->audit->record('admin.designation.updated', $designation, ['changes' => $changes]);

        return back()->with('success', 'Designation updated.');
    }

    private function validateDepartment(Request $request, ?Department $department = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('departments')->ignore($department)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Department::TYPES)],
            'parent_id' => ['nullable', 'exists:departments,id'],
            'is_active' => ['boolean'],
        ]);
    }

    private function validateDesignation(Request $request, ?Designation $designation = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('designations')->ignore($designation)],
            'name' => ['required', 'string', 'max:255'],
            'level' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
    }
}
