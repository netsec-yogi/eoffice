<?php

namespace App\Modules\Workflow\Http;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Permissions;
use App\Modules\Workflow\Forms\FieldTypes;
use App\Modules\Workflow\Forms\FormService;
use App\Modules\Workflow\Forms\OptionSources;
use App\Modules\Workflow\Models\FormTemplate;
use App\Modules\Workflow\Models\FormVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The form builder (FORM-001..005). */
class FormAdminController extends Controller
{
    public function __construct(private FormService $forms) {}

    public function index(): Response
    {
        $this->authorize(Permissions::WORKFLOWS_DESIGN);

        return Inertia::render('Admin/Forms/Index', [
            'forms' => FormTemplate::query()->with(['department:id,code', 'versions:id,form_template_id,version,status,published_at'])->orderBy('name')->get()
                ->map(fn ($t) => [
                    ...$t->only('id', 'key', 'name', 'description'),
                    'department' => $t->department?->code,
                    'published' => $t->versions->firstWhere('status', 'published')?->version,
                    'draft' => $t->versions->firstWhere('status', 'draft')?->version,
                ]),
            'departments' => Department::query()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::WORKFLOWS_DESIGN);
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_-]*$/', 'unique:form_templates,key'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);
        $template = $this->forms->create($data, $request->user());

        return redirect()->route('admin.forms.edit', $template)->with('success', 'Form created.');
    }

    public function edit(Request $request, FormTemplate $template): Response
    {
        $this->authorize(Permissions::WORKFLOWS_DESIGN);
        $versionId = $request->integer('version');
        $version = $versionId ? $template->versions()->findOrFail($versionId) : $template->versions()->first();

        return Inertia::render('Admin/Forms/Builder', [
            'template' => $template->only('id', 'key', 'name', 'description'),
            'version' => [...$version->only('id', 'version', 'status', 'schema'), 'published_at' => $version->published_at?->toIso8601String()],
            'optionSources' => app(OptionSources::class)->keys(),
            'versions' => $template->versions()->get(['id', 'version', 'status']),
            'problems' => $this->forms->problems($version),
            'fieldTypes' => FieldTypes::ALL,
            'tableColumnTypes' => FieldTypes::TABLE_COLUMNS,
            'canPublish' => $request->user()->can(Permissions::WORKFLOWS_PUBLISH),
        ]);
    }

    public function saveDraft(Request $request, FormVersion $version): RedirectResponse
    {
        $this->authorize(Permissions::WORKFLOWS_DESIGN);
        $data = $request->validate(['schema' => ['required', 'array'], 'schema.sections' => ['required', 'array', 'max:30']]);
        $this->forms->saveDraft($version, $data['schema'], $request->user());

        return back()->with('success', 'Draft saved.');
    }

    public function publish(Request $request, FormVersion $version): RedirectResponse
    {
        $this->authorize(Permissions::WORKFLOWS_PUBLISH);
        $this->forms->publish($version, $request->user());

        return back()->with('success', "Form version {$version->version} published. Attach it to a workflow draft to use it.");
    }

    public function newDraft(Request $request, FormTemplate $template): RedirectResponse
    {
        $this->authorize(Permissions::WORKFLOWS_DESIGN);
        $draft = $this->forms->draft($template, $request->user());

        return redirect()->route('admin.forms.edit', ['template' => $template, 'version' => $draft->id]);
    }
}
