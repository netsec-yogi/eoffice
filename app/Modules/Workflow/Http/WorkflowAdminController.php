<?php

namespace App\Modules\Workflow\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Calendar\Services\BusinessTime;
use App\Modules\Identity\Models\Authority;
use App\Modules\Identity\Models\Committee;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Permissions;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\OfficialDocuments\Models\WorkflowDocumentOutput;
use App\Modules\Workflow\Automation\ActionRegistry;
use App\Modules\Workflow\Engine\CommitteeRule;
use App\Modules\Workflow\Graph\WorkflowDesigner;
use App\Modules\Workflow\Models\FormVersion;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowNode;
use App\Modules\Workflow\Models\WorkflowTransition;
use App\Modules\Workflow\Models\WorkflowVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/** Workflow definitions and the graphical designer (WF-001..004). */
class WorkflowAdminController extends Controller
{
    public function __construct(private WorkflowDesigner $designer) {}

    public function index(): Response
    {
        $this->authorize(Permissions::WORKFLOWS_DESIGN);

        return Inertia::render('Admin/Workflows/Index', [
            'definitions' => WorkflowDefinition::query()->with(['department:id,code', 'currentVersion:id,workflow_definition_id,version,published_at', 'draftVersion:id,workflow_definition_id,version'])
                ->withCount(['versions'])->orderBy('name')->get()
                ->map(fn ($d) => [
                    ...$d->only('id', 'key', 'name', 'category', 'is_active'),
                    'department' => $d->department?->code,
                    'published' => $d->currentVersion?->version,
                    'published_at' => $d->currentVersion?->published_at?->toIso8601String(),
                    'draft' => $d->draftVersion?->version,
                    'open' => WorkflowInstance::query()->where('workflow_definition_id', $d->id)->whereIn('status', ['in_progress', 'returned', 'on_hold'])->count(),
                    // Official PDF forms this workflow issues on final approval (DOC-003).
                    'official_pdfs' => WorkflowDocumentOutput::query()->where('workflow_definition_id', $d->id)->where('is_enabled', true)->with('template:id,name')->get()
                        ->map(fn ($o) => ['id' => $o->pdf_template_id, 'name' => $o->template->name])->values(),
                ]),
            'canManageTemplates' => request()->user()->can(Permissions::OFFICIAL_TEMPLATES_MANAGE),
            'departments' => Department::query()->orderBy('name')->get(['id', 'code', 'name']),
            'categories' => config('workflow.categories'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::WORKFLOWS_DESIGN);
        $definition = $this->designer->create($this->validated($request), $request->user());

        return redirect()->route('admin.workflows.edit', $definition)->with('success', 'Workflow created. Design its steps, then publish.');
    }

    public function update(Request $request, WorkflowDefinition $definition): RedirectResponse
    {
        $this->authorize(Permissions::WORKFLOWS_DESIGN);
        $definition->fill($this->validated($request, $definition));
        $changes = $definition->getDirty();
        $definition->save();
        app(AuditLogger::class)->record('workflow.definition.updated', $definition, ['changes' => $changes]);

        return back()->with('success', 'Workflow details saved.');
    }

    /** The designer, on the current draft (opened from the latest version if none). */
    public function edit(Request $request, WorkflowDefinition $definition): Response
    {
        $this->authorize(Permissions::WORKFLOWS_DESIGN);
        $versionId = $request->integer('version');
        $version = $versionId
            ? WorkflowVersion::query()->where('workflow_definition_id', $definition->id)->findOrFail($versionId)
            : ($definition->draftVersion()->first() ?? $definition->versions()->first());
        $version->load('nodes', 'transitions', 'formVersion.template');

        return Inertia::render('Admin/Workflows/Designer', [
            'definition' => $definition->only('id', 'key', 'name', 'description', 'category', 'department_id', 'numbering_pattern', 'is_active'),
            'version' => [
                ...$version->only('id', 'version', 'status', 'settings', 'form_version_id', 'change_note'),
                'published_at' => $version->published_at?->toIso8601String(),
                'nodes' => $version->nodes->map(fn (WorkflowNode $n) => [...$n->only('key', 'type', 'name', 'config'), 'x' => $n->position_x, 'y' => $n->position_y]),
                'edges' => $version->transitions->map(fn (WorkflowTransition $t) => $t->only('key', 'from_node', 'to_node', 'outcome', 'condition', 'priority', 'label')),
                'form_schema' => $version->formVersion?->schema,
            ],
            'versions' => $definition->versions()->get(['id', 'version', 'status', 'published_at', 'change_note'])
                ->map(fn ($v) => [...$v->only('id', 'version', 'status', 'change_note'), 'published_at' => $v->published_at?->toIso8601String()]),
            'check' => $this->designer->check($version),
            'catalogue' => $this->catalogue(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'code', 'name']),
            'categories' => config('workflow.categories'),
            'canPublish' => $request->user()->can(Permissions::WORKFLOWS_PUBLISH),
        ]);
    }

    public function saveDraft(Request $request, WorkflowVersion $version): RedirectResponse
    {
        $this->authorize(Permissions::WORKFLOWS_DESIGN);
        $data = $request->validate([
            'nodes' => ['required', 'array', 'min:2', 'max:200'],
            'nodes.*.key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'nodes.*.type' => ['required', Rule::in(WorkflowNode::TYPES)],
            'nodes.*.name' => ['required', 'string', 'max:255'],
            'nodes.*.config' => ['nullable', 'array'],
            'nodes.*.x' => ['numeric'],
            'nodes.*.y' => ['numeric'],
            'edges' => ['present', 'array', 'max:400'],
            'edges.*.key' => ['nullable', 'string', 'max:64'],
            'edges.*.from_node' => ['required', 'string'],
            'edges.*.to_node' => ['required', 'string'],
            'edges.*.outcome' => ['required', Rule::in(WorkflowTransition::OUTCOMES)],
            'edges.*.condition' => ['nullable', 'array'],
            'edges.*.priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'edges.*.label' => ['nullable', 'string', 'max:100'],
            'settings' => ['required', 'array'],
            'form_version_id' => ['nullable', 'exists:form_versions,id'],
        ]);
        $this->designer->saveDraft($version, $data, $request->user());

        return back()->with('success', 'Draft saved.');
    }

    public function publish(Request $request, WorkflowVersion $version): RedirectResponse
    {
        $this->authorize(Permissions::WORKFLOWS_PUBLISH);
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        $this->designer->publish($version, $request->user(), $data['note']);

        return redirect()->route('admin.workflows.edit', $version->workflow_definition_id)->with('success', "Version {$version->version} published. New requests use it; running requests keep their version.");
    }

    public function newDraft(Request $request, WorkflowDefinition $definition): RedirectResponse
    {
        $this->authorize(Permissions::WORKFLOWS_DESIGN);
        $draft = $this->designer->draft($definition, $request->user());

        return redirect()->route('admin.workflows.edit', ['definition' => $definition, 'version' => $draft->id]);
    }

    public function check(WorkflowVersion $version): JsonResponse
    {
        $this->authorize(Permissions::WORKFLOWS_DESIGN);

        return response()->json($this->designer->check($version));
    }

    /** Everything the designer's property panels offer. */
    private function catalogue(): array
    {
        return [
            'node_types' => WorkflowNode::TYPES,
            'authorities' => Authority::query()->where('is_active', true)->orderBy('name')->get(['code', 'name', 'scope']),
            'committees' => Committee::query()->where('is_active', true)->orderBy('name')->get(['code', 'name']),
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'actions' => app(ActionRegistry::class)->catalogue(),
            'templates' => NotificationTemplate::query()->distinct()->orderBy('key')->pluck('key'),
            'committee_rules' => CommitteeRule::RULES,
            'units' => BusinessTime::UNITS,
            'forms' => FormVersion::query()->where('status', 'published')->with('template:id,key,name')->orderByDesc('id')->get()
                ->map(fn ($f) => ['id' => $f->id, 'label' => "{$f->template->name} (v{$f->version})", 'schema' => $f->schema]),
            'signature_provider' => (bool) config('signatures.provider'),
            'validators' => collect(config('workflow.validators'))->map(fn ($class, $key) => ['key' => $key, 'label' => app($class)->label(), 'provides' => app($class)->provides()])->values(),
        ];
    }

    private function validated(Request $request, ?WorkflowDefinition $definition = null): array
    {
        return $request->validate([
            'key' => [$definition ? 'prohibited' : 'required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_-]*$/', Rule::unique('workflow_definitions')->ignore($definition)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'category' => ['required', 'string', 'max:64', 'regex:/^[a-z_]+$/'],
            'numbering_pattern' => ['nullable', 'string', 'max:100', 'regex:/\{SEQ(:\d+)?\}/'],
            'is_active' => ['boolean'],
        ]) + ['numbering_pattern' => '{KEY}/{YYYY}/{SEQ:5}'];
    }
}
