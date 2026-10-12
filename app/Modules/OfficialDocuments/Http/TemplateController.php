<?php

namespace App\Modules\OfficialDocuments\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Permissions;
use App\Modules\OfficialDocuments\Models\OfficialDocument;
use App\Modules\OfficialDocuments\Models\PdfTemplate;
use App\Modules\OfficialDocuments\Models\PdfTemplateVersion;
use App\Modules\OfficialDocuments\Models\WorkflowDocumentOutput;
use App\Modules\OfficialDocuments\Pdf\GenerationException;
use App\Modules\OfficialDocuments\Pdf\PdfRenderer;
use App\Modules\OfficialDocuments\Pdf\TemplateInspector;
use App\Modules\OfficialDocuments\Services\DocumentData;
use App\Modules\OfficialDocuments\Services\MappingValidator;
use App\Modules\OfficialDocuments\Services\TemplateService;
use App\Modules\Workflow\Forms\FormDataValidator;
use App\Modules\Workflow\Models\WorkflowDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Administrator panel: official PDF templates, versions, mappings and workflow outputs (DOC-001..003). */
class TemplateController extends Controller
{
    public function __construct(private TemplateService $templates) {}

    public function index(): Response
    {
        $this->authorize(Permissions::OFFICIAL_TEMPLATES_MANAGE);

        return Inertia::render('Admin/PdfTemplates/Index', [
            'templates' => PdfTemplate::query()->with(['published', 'draft', 'outputs.definition:id,key,name', 'department:id,code'])->orderBy('name')->get()
                ->map(fn (PdfTemplate $t) => [...$t->only('id', 'key', 'name', 'description', 'is_active'), 'department' => $t->department?->code,
                    'published' => $t->published?->only('id', 'version', 'page_count', 'original_filename'), 'draft' => $t->draft?->only('id', 'version'),
                    'workflows' => $t->outputs->where('is_enabled', true)->map(fn ($o) => $o->definition->name)->values()]),
            'departments' => $this->departments(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::OFFICIAL_TEMPLATES_MANAGE);
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9-]*$/', Rule::unique('pdf_templates', 'key')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'file' => ['required', 'file'],
        ]);
        $template = $this->templates->create(collect($data)->except('file')->all(), $request->file('file'), $request->user());

        return redirect()->route('admin.pdf-templates.show', $template)->with('success', 'Template uploaded. Map its fields, preview and publish it.');
    }

    public function show(PdfTemplate $template): Response
    {
        $this->authorize(Permissions::OFFICIAL_TEMPLATES_MANAGE);
        $template->load(['versions.original:id,uuid', 'versions.publisher:id,name', 'outputs.definition:id,key,name', 'department:id,code,name']);

        return Inertia::render('Admin/PdfTemplates/Show', [
            'template' => $template->only('id', 'key', 'name', 'description', 'department_id', 'is_active'),
            'versions' => $template->versions->where('status', '!=', 'discarded')->values()->map(fn (PdfTemplateVersion $v) => [
                ...$v->only('id', 'version', 'status', 'original_filename', 'original_sha256', 'page_count', 'change_note'),
                'fields' => count($v->mapping), 'form_fields' => count($v->form_fields), 'original' => $v->original?->uuid,
                'published_at' => $v->published_at?->toIso8601String(), 'published_by' => $v->publisher?->name, 'created_at' => $v->created_at->toIso8601String(),
                'documents' => OfficialDocument::query()->where('pdf_template_version_id', $v->id)->count(),
            ]),
            'outputs' => $template->outputs->map(fn (WorkflowDocumentOutput $o) => [
                ...$o->only('id', 'workflow_definition_id', 'is_enabled', 'required_steps', 'condition', 'download_groups', 'confidential', 'requires_signature', 'filename_pattern', 'regeneration'),
                'workflow' => $o->definition->name,
            ]),
            'workflows' => WorkflowDefinition::query()->with('currentVersion.nodes')->orderBy('name')->get()->map(fn ($d) => [
                'id' => $d->id, 'name' => $d->name, 'key' => $d->key,
                'steps' => $d->currentVersion?->nodes->whereIn('type', ['approval', 'review', 'verification', 'committee', 'signature'])->map(fn ($n) => ['value' => $n->key, 'label' => $n->name])->values() ?? [],
            ]),
            'groups' => collect(WorkflowDocumentOutput::GROUPS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'departments' => $this->departments(),
            'signatureProvider' => (bool) config('signatures.provider'),
        ]);
    }

    public function update(Request $request, PdfTemplate $template): RedirectResponse
    {
        $this->authorize(Permissions::OFFICIAL_TEMPLATES_MANAGE);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'], 'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')], 'is_active' => ['boolean']]);
        if (array_key_exists('is_active', $data) && $data['is_active'] !== $template->is_active) {
            $this->templates->setActive($template, $data['is_active'], $request->user());
        }
        $template->fill(collect($data)->except('is_active')->all());
        if ($template->isDirty()) {
            $changed = array_keys($template->getDirty());
            $template->save();
            app(AuditLogger::class)->record('official_template.updated', $template, ['changed' => $changed], actor: $request->user());
        }

        return back()->with('success', 'Saved.');
    }

    public function storeVersion(Request $request, PdfTemplate $template): RedirectResponse
    {
        $this->authorize(Permissions::OFFICIAL_TEMPLATES_MANAGE);
        $data = $request->validate(['file' => ['required', 'file'], 'change_note' => ['required', 'string', 'max:2000']]);
        $version = $this->templates->addVersion($template, $request->file('file'), $request->user(), $data['change_note']);

        return redirect()->route('admin.pdf-templates.mapper', $version)->with('success', "Version {$version->version} uploaded as a draft. Requests keep the version they were submitted with.");
    }

    public function mapper(PdfTemplateVersion $version): Response
    {
        $this->authorize(Permissions::OFFICIAL_TEMPLATES_MANAGE);
        $version->load('template.outputs.definition.currentVersion.formVersion', 'template.outputs.definition.currentVersion.nodes');

        return Inertia::render('Admin/PdfTemplates/Mapper', [
            'template' => $version->template->only('id', 'key', 'name'),
            'version' => [...$version->only('id', 'version', 'status', 'pages', 'form_fields', 'mapping', 'original_filename'), 'editable' => $version->isDraft()],
            'sources' => $this->sources($version),
        ]);
    }

    /** A page of the template as an image, for placing fields. */
    public function page(PdfTemplateVersion $version, int $page, TemplateInspector $inspector): HttpResponse
    {
        $this->authorize(Permissions::OFFICIAL_TEMPLATES_MANAGE);
        abort_unless($page >= 1 && $page <= $version->page_count, 404);
        $disk = Storage::disk(config('documents.disk'));
        $cache = dirname((string) $version->working_path)."/v{$version->version}-page-{$page}.png";
        if (! $disk->exists($cache)) {
            $disk->put($cache, $inspector->renderPage($disk->path((string) $version->working_path), $page, 96));
        }

        return response($disk->get($cache), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=600', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function saveMapping(Request $request, PdfTemplateVersion $version): RedirectResponse
    {
        $this->authorize(Permissions::OFFICIAL_TEMPLATES_MANAGE);
        $request->validate(['mapping' => ['present', 'array', 'max:300']]);
        $this->templates->saveMapping($version, $request->input('mapping'), $request->user());

        return back()->with('success', 'Mapping saved.');
    }

    /**
     * Populated preview with synthetic data (never a real person's request),
     * from the mapping as currently edited. Returns page images, or the
     * reason it cannot be generated.
     */
    public function preview(Request $request, PdfTemplateVersion $version, PdfRenderer $renderer, DocumentData $data, MappingValidator $validator, TemplateInspector $inspector): JsonResponse|HttpResponse
    {
        $this->authorize(Permissions::OFFICIAL_TEMPLATES_MANAGE);
        $mapping = $request->isMethod('post') ? (array) $request->input('mapping', []) : $version->mapping;
        if ($problems = $validator->problems($mapping, $version->pages)) {
            return response()->json(['message' => $problems[0]], 422);
        }
        $working = Storage::disk(config('documents.disk'))->path((string) $version->working_path);
        try {
            $bytes = $renderer->render($working, $version->pages, $mapping, $data->sample($mapping), ['title' => 'PREVIEW - '.$version->template->name]);
        } catch (GenerationException $e) {
            return response()->json(['message' => $e->getMessage(), 'field' => $e->field], 422);
        }
        if ($request->isMethod('get')) {
            return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="preview.pdf"', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
        $path = tempnam(TemplateInspector::tempDir(), 'prv');
        file_put_contents($path, $bytes);
        try {
            $pages = [];
            for ($i = 1; $i <= $version->page_count; $i++) {
                $pages[] = 'data:image/png;base64,'.base64_encode($inspector->renderPage($path, $i, 96));
            }
        } finally {
            @unlink($path);
        }

        return response()->json(['pages' => $pages]);
    }

    public function publish(Request $request, PdfTemplateVersion $version): RedirectResponse
    {
        $this->authorize(Permissions::OFFICIAL_TEMPLATES_MANAGE);
        $this->templates->publish($version, $request->user());

        return redirect()->route('admin.pdf-templates.show', $version->pdf_template_id)->with('success', "Version {$version->version} published. New requests use it; earlier ones keep theirs.");
    }

    public function discard(Request $request, PdfTemplateVersion $version): RedirectResponse
    {
        $this->authorize(Permissions::OFFICIAL_TEMPLATES_MANAGE);
        $this->templates->discard($version, $request->user());

        return redirect()->route('admin.pdf-templates.show', $version->pdf_template_id)->with('success', 'Draft discarded.');
    }

    public function saveOutput(Request $request, PdfTemplate $template): RedirectResponse
    {
        $this->authorize(Permissions::OFFICIAL_TEMPLATES_MANAGE);
        $data = $request->validate([
            'workflow_definition_id' => ['required', 'integer', Rule::exists('workflow_definitions', 'id')],
            'is_enabled' => ['boolean'],
            'required_steps' => ['array'], 'required_steps.*' => ['string', 'max:64'],
            'condition' => ['nullable', 'array'],
            'download_groups' => ['required', 'array', 'min:1'], 'download_groups.*' => [Rule::in(array_keys(WorkflowDocumentOutput::GROUPS))],
            'confidential' => ['boolean'], 'requires_signature' => ['boolean'],
            'filename_pattern' => ['required', 'string', 'max:150', 'regex:/\{reference\}/'],
            'regeneration' => ['required', Rule::in(['authorised', 'never'])],
        ], ['filename_pattern.regex' => 'The file name must contain {reference}.']);
        $this->templates->saveOutput($template, $data, $request->user());

        return back()->with('success', 'Workflow output saved.');
    }

    public function disableOutput(Request $request, WorkflowDocumentOutput $output): RedirectResponse
    {
        $this->authorize(Permissions::OFFICIAL_TEMPLATES_MANAGE);
        $this->templates->removeOutput($output, $request->user());

        return back()->with('success', 'The workflow no longer produces this document. Documents already issued are kept.');
    }

    /** Data a field can be mapped to, from the workflows that use this template. */
    private function sources(PdfTemplateVersion $version): array
    {
        $groups = [
            ['group' => 'Applicant', 'items' => array_map(fn ($k) => ['value' => "applicant.{$k}", 'label' => ucfirst(str_replace('_', ' ', $k))], DocumentData::APPLICANT)],
            ['group' => 'Request', 'items' => array_map(fn ($k) => ['value' => "workflow.{$k}", 'label' => ucfirst(str_replace('_', ' ', $k))], DocumentData::WORKFLOW)],
            ['group' => 'Institute', 'items' => array_map(fn ($k) => ['value' => "institute.{$k}", 'label' => ucfirst(str_replace('_', ' ', $k))], array_keys(config('official_documents.institute')))],
            ['group' => 'Document', 'items' => array_map(fn ($k) => ['value' => "document.{$k}", 'label' => ucfirst(str_replace('_', ' ', $k))], DocumentData::DOCUMENT)],
        ];
        $forms = app(FormDataValidator::class);
        foreach ($version->template->outputs as $o) {
            $v = $o->definition->currentVersion;
            if (! $v) {
                continue;
            }
            $items = [];
            foreach ($forms->fields($v->formVersion?->schema ?? ['sections' => []]) as $f) {
                $items[] = ['value' => "form.{$f['key']}", 'label' => ($f['label'] ?? $f['key']).' (as entered)', 'type' => $f['type']];
                if (in_array($f['type'], ['select', 'radio', 'multiselect', 'user', 'department'], true)) {
                    $items[] = ['value' => "form_label.{$f['key']}", 'label' => ($f['label'] ?? $f['key']).' (as shown)', 'type' => $f['type']];
                }
                if ($f['type'] === 'table') {
                    $items[count($items) - 1]['columns'] = array_map(fn ($c) => ['value' => $c['key'], 'label' => $c['label'] ?? $c['key']], $f['columns'] ?? []);
                }
            }
            foreach ($v->nodes->whereIn('type', ['approval', 'review', 'verification', 'committee']) as $n) {
                foreach (DocumentData::APPROVAL as $attr) {
                    $items[] = ['value' => "approval.{$n->key}.{$attr}", 'label' => "{$n->name}: ".str_replace('_', ' ', $attr)];
                }
            }
            $items[] = ['value' => 'approvals', 'label' => 'All approvals (table: step, name, designation, date, remarks)', 'type' => 'table',
                'columns' => array_map(fn ($c) => ['value' => $c, 'label' => $c], ['step', 'name', 'designation', 'department', 'date', 'remarks', 'statement'])];
            $groups[] = ['group' => $o->definition->name, 'items' => $items];
        }

        return $groups;
    }

    private function departments()
    {
        return Department::query()->orderBy('name')->get(['id', 'code', 'name'])->map(fn ($d) => ['value' => $d->id, 'label' => "{$d->name} ({$d->code})"]);
    }
}
