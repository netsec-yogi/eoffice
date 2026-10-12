<?php

namespace App\Modules\Workflow\Http;

use App\Http\Controllers\Controller;
use App\Modules\EFile\Models\EFile;
use App\Modules\EFile\Services\EFileService;
use App\Modules\EFile\Services\FileAccess;
use App\Modules\Identity\Models\Department;
use App\Modules\Workflow\Engine\WorkflowEngine;
use App\Modules\Workflow\Forms\OptionSources;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Starting, following and answering one's own requests. */
class RequestController extends Controller
{
    public function __construct(private WorkflowEngine $engine, private InstancePresenter $presenter) {}

    /** Every workflow the user may start, grouped by category. */
    public function catalogue(Request $request): Response
    {
        $user = $request->user();
        $definitions = WorkflowDefinition::query()->where('is_active', true)
            ->whereHas('currentVersion')->with(['currentVersion', 'department:id,code,name'])->orderBy('name')->get()
            ->filter(fn ($d) => $this->engine->canStart($d->currentVersion, $user))
            ->map(fn ($d) => [
                'key' => $d->key,
                'name' => $d->name,
                'description' => $d->description,
                'category' => $d->category,
                'department' => $d->department?->name,
            ])->values();

        return Inertia::render('Requests/Catalogue', ['workflows' => $definitions]);
    }

    public function create(Request $request, WorkflowDefinition $definition): Response
    {
        $version = $definition->currentVersion()->with('formVersion')->firstOrFail();
        abort_unless($definition->is_active && $this->engine->canStart($version, $request->user()), 403, 'You are not eligible to start this workflow.');

        return Inertia::render('Requests/Form', [
            'workflow' => $definition->only('key', 'name', 'description'),
            'schema' => app(OptionSources::class)->resolve($version->formVersion?->schema ?? ['sections' => []], $request->user()),
            'data' => (object) [],
            'instance' => null,
            'mode' => 'create',
            'departments' => $this->departments(),
            'efile' => ($file = $this->fileFor($request)) ? $file->only('id', 'file_number', 'subject') : null,
        ]);
    }

    public function store(Request $request, WorkflowDefinition $definition): RedirectResponse
    {
        $request->validate(['data' => ['array'], 'submit' => ['boolean'], 'efile' => ['nullable', 'integer']]);
        $submit = $request->boolean('submit', true);
        $file = $this->fileFor($request);
        $instance = $this->engine->start($definition, $request->user(), $request->input('data', []), $submit, $file);
        if ($file) {
            app(EFileService::class)->link($file, $request->user(), $instance, 'approval');
        }

        return $this->afterSave($request, $instance, $submit ? "Submitted as {$instance->reference}." : 'Draft saved.');
    }

    public function index(Request $request): Response
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(['draft', 'in_progress', 'returned', 'on_hold', 'completed', 'rejected', 'cancelled'])], 'q' => ['nullable', 'string', 'max:100']]);
        $instances = WorkflowInstance::query()->where('initiator_id', $request->user()->id)
            ->with(['definition:id,name', 'tasks' => fn ($q) => $q->where('status', 'pending')->with('assignee:id,name')])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['q'] ?? null, fn ($q, $t) => $q->where(fn ($q) => $q->where('reference', 'ilike', "%{$t}%")->orWhere('title', 'ilike', "%{$t}%")))
            ->latest('updated_at')->paginate(20)->withQueryString()
            ->through(fn ($i) => $this->presenter->summary($i));

        return Inertia::render('Requests/Index', [
            'instances' => $instances,
            'filters' => $filters,
            'counts' => WorkflowInstance::query()->where('initiator_id', $request->user()->id)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(Request $request, WorkflowInstance $instance): Response
    {
        $this->authorize('view', $instance);

        return Inertia::render('Requests/Show', ['instance' => $this->presenter->detail($instance, $request->user()), 'focusTask' => $request->integer('task') ?: null]);
    }

    /** Edit a draft, or answer a return for clarification. */
    public function edit(Request $request, WorkflowInstance $instance): Response
    {
        $this->authorize('edit', $instance);
        $instance->load('definition', 'formVersion');
        $returned = $instance->actions()->where('action', 'returned')->with('actor:id,name')->reorder()->orderByDesc('id')->first();

        $schema = app(OptionSources::class)->resolve($instance->formVersion?->schema ?? ['sections' => []], $request->user());
        $data = $instance->data ?? [];
        if ($request->user()->isExternal()) {
            [$schema, $data] = $this->initiatorView($schema, $data);
        }

        return Inertia::render('Requests/Form', [
            'workflow' => $instance->definition->only('key', 'name', 'description'),
            'schema' => $schema,
            'data' => (object) $data,
            'instance' => ['id' => $instance->id, 'reference' => $instance->reference, 'status' => $instance->status,
                'returned' => $instance->status === 'returned' && $returned ? ['by' => $returned->actor?->name, 'remarks' => $returned->remarks, 'kind' => $returned->data['kind'] ?? 'clarification'] : null],
            'mode' => $instance->status === 'draft' ? 'draft' : 'resubmit',
            'departments' => $this->departments(),
        ]);
    }

    public function update(Request $request, WorkflowInstance $instance): RedirectResponse
    {
        $this->authorize('edit', $instance);
        $request->validate(['data' => ['array'], 'submit' => ['boolean'], 'remarks' => ['nullable', 'string', 'max:5000']]);
        $data = $request->input('data', []);

        if ($instance->status === 'returned') {
            $this->engine->resubmit($instance, $request->user(), $data, $request->input('remarks'));

            return $this->afterSave($request, $instance, 'Resubmitted.');
        }
        if ($request->boolean('submit')) {
            $this->engine->submit($instance, $request->user(), $data);

            return redirect()->route('requests.show', $instance)->with('success', 'Submitted as '.$instance->fresh()->reference.'.');
        }
        $this->engine->saveDraft($instance, $request->user(), $data);

        return back()->with('success', 'Draft saved.');
    }

    /**
     * External parties see only what they themselves fill in: fields that
     * officers complete later (deductions, internal notes) are removed from
     * both the schema and the data before anything reaches the browser.
     *
     * @return array{0: array, 1: array}
     */
    private function initiatorView(array $schema, array $data): array
    {
        $own = fn (array $f) => ! empty($f['editable_at']) ? in_array('start', $f['editable_at'], true) : ! in_array($f['type'] ?? '', ['remark', 'calculated'], true);
        $keep = [];
        foreach ($schema['sections'] ?? [] as $i => $section) {
            $fields = array_values(array_filter($section['fields'] ?? [], $own));
            $schema['sections'][$i]['fields'] = $fields;
            array_push($keep, ...array_column($fields, 'key'));
        }
        $schema['sections'] = array_values(array_filter($schema['sections'] ?? [], fn ($s) => $s['fields'] !== []));

        return [$schema, array_intersect_key($data, array_flip($keep))];
    }

    /** Back to the request, or for external parties (who cannot see it) to their portal. */
    private function afterSave(Request $request, WorkflowInstance $instance, string $message): RedirectResponse
    {
        if ($request->user()->can('view', $instance)) {
            return redirect()->route('requests.show', $instance)->with('success', $message);
        }

        return redirect()->route($request->user()->category === 'vendor' ? 'vendor.portal' : 'dashboard')->with('success', $message);
    }

    /** A file the user holds, when a request is raised from it (routing a file for approval). */
    private function fileFor(Request $request): ?EFile
    {
        $id = $request->integer('efile');
        if (! $id) {
            return null;
        }
        $file = EFile::query()->findOrFail($id);
        abort_unless(app(FileAccess::class)->canAct($request->user(), $file), 409, 'The file must be with you to route it.');

        return $file;
    }

    private function departments()
    {
        return Department::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    public function cancel(Request $request, WorkflowInstance $instance): RedirectResponse
    {
        $this->authorize('view', $instance);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->engine->cancel($instance, $request->user(), $data['reason']);

        return back()->with('success', 'Request cancelled.');
    }

    public function hold(Request $request, WorkflowInstance $instance): RedirectResponse
    {
        $this->authorize('view', $instance);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->engine->hold($instance, $request->user(), $data['reason']);

        return back()->with('success', 'Request put on hold. Deadlines are paused.');
    }

    public function resume(Request $request, WorkflowInstance $instance): RedirectResponse
    {
        $this->authorize('view', $instance);
        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:2000']]);
        $this->engine->resume($instance, $request->user(), $data['remarks'] ?? null);

        return back()->with('success', 'Request resumed.');
    }
}
