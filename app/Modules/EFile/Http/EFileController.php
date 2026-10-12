<?php

namespace App\Modules\EFile\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Documents\Models\Document;
use App\Modules\EFile\Models\EFile;
use App\Modules\EFile\Models\EFileAccessGrant;
use App\Modules\EFile\Models\EFileEvent;
use App\Modules\EFile\Models\EFileMovement;
use App\Modules\EFile\Models\EFileNote;
use App\Modules\EFile\Models\FileCategory;
use App\Modules\EFile\Services\EFileService;
use App\Modules\EFile\Services\FileAccess;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EFileController extends Controller
{
    public function __construct(private EFileService $files, private FileAccess $access) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $f = $request->validate([
            'tab' => ['nullable', Rule::in(['with_me', 'incoming', 'sent', 'all', 'register'])],
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(['open', 'closed', 'archived', 'destroyed'])],
            'category' => ['nullable', 'integer'],
            'department' => ['nullable', 'integer'],
        ]);
        $tab = $f['tab'] ?? 'with_me';
        abort_if($tab === 'register' && ! $user->can(Permissions::EFILES_RECORDS), 403);

        $query = EFile::query()->with(['category:id,code,name', 'department:id,code', 'custodian:id,name', 'inTransit.to:id,name']);
        match ($tab) {
            'with_me' => $query->where('custodian_id', $user->id)->where('status', 'open')->whereDoesntHave('inTransit'),
            'incoming' => $query->whereHas('inTransit', fn ($m) => $m->where('to_user_id', $user->id)),
            'sent' => $query->whereHas('inTransit', fn ($m) => $m->where('from_user_id', $user->id)),
            'all' => $this->access->scope($query, $user),
            'register' => $query, // metadata of every file, for records officers
        };
        $query->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($q) => $q->where('file_number', 'ilike', "%{$t}%")
            ->orWhere(fn ($q) => $q->whereNotIn('classification', ['confidential', 'restricted'])->where('subject', 'ilike', "%{$t}%"))))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['category'] ?? null, fn ($q, $c) => $q->where('file_category_id', $c))
            ->when($f['department'] ?? null, fn ($q, $d) => $q->where('department_id', $d));

        $files = $query->latest('updated_at')->paginate(25)->withQueryString()->through(fn (EFile $e) => [
            ...$e->only('id', 'file_number', 'classification', 'status', 'note_count', 'disposition', 'legal_hold'),
            // Subjects of classified files are not shown in lists to people who cannot open them.
            'subject' => $e->isSensitive() && $tab === 'register' && ! $this->access->allows($user, $e) ? '(classified)' : $e->subject,
            'category' => $e->category->name,
            'department' => $e->department->code,
            'custodian' => $e->custodian->name,
            'in_transit_to' => $e->inTransit?->to?->name,
            'updated_at' => $e->updated_at->toIso8601String(),
        ]);

        $pending = fn ($q) => $q->where('status', 'in_transit');

        return Inertia::render('Files/Index', [
            'tab' => $tab,
            'files' => $files,
            'filters' => $f,
            'counts' => [
                'with_me' => EFile::query()->where('custodian_id', $user->id)->where('status', 'open')->whereDoesntHave('inTransit')->count(),
                'incoming' => EFileMovement::query()->where('to_user_id', $user->id)->where($pending)->count(),
                'sent' => EFileMovement::query()->where('from_user_id', $user->id)->where($pending)->count(),
            ],
            'categories' => FileCategory::query()->orderBy('name')->get(['id', 'name']),
            'departments' => Department::query()->orderBy('name')->get(['id', 'code', 'name']),
            'can' => ['open' => $user->can(Permissions::EFILES_OPEN), 'register' => $user->can(Permissions::EFILES_RECORDS)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize(Permissions::EFILES_OPEN);
        $user = $request->user();
        $departments = $user->can(Permissions::EFILES_RECORDS)
            ? Department::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])
            : Department::query()->whereKey($user->department_id ? Department::query()->find($user->department_id)?->descendantIds() ?? [] : [])->orderBy('name')->get(['id', 'code', 'name']);

        return Inertia::render('Files/Create', [
            'categories' => FileCategory::query()->where('is_active', true)->with('retention:id,name')->orderBy('name')->get()
                ->map(fn ($c) => [...$c->only('id', 'code', 'name', 'description', 'numbering_pattern', 'default_classification'), 'retention' => $c->retention?->name]),
            'departments' => $departments,
            'defaultDepartment' => $user->department_id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::EFILES_OPEN);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'file_category_id' => ['required', 'exists:file_categories,id'],
            'department_id' => ['required', 'exists:departments,id'],
            'classification' => ['required', Rule::in(EFile::CLASSIFICATIONS)],
        ]);
        $file = $this->files->open($data, $request->user());

        return redirect()->route('efiles.show', $file)->with('success', "File {$file->file_number} opened.");
    }

    public function show(Request $request, EFile $efile, AuditLogger $audit): Response
    {
        $user = $request->user();
        $canView = $this->access->allows($user, $efile);
        $records = $user->can(Permissions::EFILES_RECORDS);
        abort_unless($canView || $records, 403, 'You do not have access to this file.');

        if ($efile->isSensitive()) {
            $audit->record('efile.viewed', $efile, ['contents' => $canView], actor: $user);
        }
        $efile->load(['category:id,code,name', 'department:id,code,name', 'opener:id,name', 'custodian:id,name', 'retention:id,code,name,retain_years,action', 'inTransit.to:id,name', 'inTransit.from:id,name']);

        $base = [
            ...$efile->only('id', 'file_number', 'description', 'classification', 'status', 'note_count', 'legal_hold', 'disposition', 'closure_remarks'),
            'subject' => $canView || ! $efile->isSensitive() ? $efile->subject : '(classified)',
            'category' => $efile->category->name,
            'department' => $efile->department->name,
            'opened_by' => $efile->opener->name,
            'opened_at' => $efile->created_at->toIso8601String(),
            'custodian' => $efile->custodian->only('id', 'name'),
            'retention' => $efile->retention?->only('code', 'name', 'retain_years', 'action'),
            'retain_until' => $efile->retain_until?->toDateString(),
            'closed_at' => $efile->closed_at?->toIso8601String(),
            'in_transit' => $efile->inTransit ? [
                'id' => $efile->inTransit->id, 'to' => $efile->inTransit->to->name, 'from' => $efile->inTransit->from->name,
                'to_me' => $efile->inTransit->to_user_id === $user->id, 'from_me' => $efile->inTransit->from_user_id === $user->id,
                'action' => EFileMovement::ACTIONS[$efile->inTransit->required_action], 'reason' => $efile->inTransit->reason, 'sent_at' => $efile->inTransit->sent_at->toIso8601String(),
            ] : null,
        ];

        return Inertia::render('Files/Show', [
            'file' => $base,
            'contents' => $canView ? $this->contents($efile, $user) : null,
            'can' => [
                'act' => $this->access->canAct($user, $efile),
                'grant' => $canView && in_array($user->id, [$efile->custodian_id, $efile->opened_by], true),
                'reopen' => $efile->status === 'closed' && ($efile->custodian_id === $user->id || $records),
                'records' => $records,
            ],
            'actions' => EFileMovement::ACTIONS,
            'kinds' => EFileNote::KINDS,
            'approvalWorkflows' => WorkflowDefinition::query()->where('is_active', true)->where('category', 'file_approval')->whereHas('currentVersion')->orderBy('name')->get(['key', 'name']),
        ]);
    }

    private function contents(EFile $file, User $user): array
    {
        $notes = EFileNote::query()->where('efile_id', $file->id)
            ->where(fn ($q) => $q->where('status', 'final')->orWhere('author_id', $user->id))
            ->with('corrects:id,number')->orderByRaw('number nulls last')->orderBy('id')->get();

        return [
            'notes' => $notes->map(fn (EFileNote $n) => [
                ...$n->only('id', 'number', 'kind', 'body', 'status', 'author_name', 'author_designation', 'references'),
                'corrects' => $n->corrects?->number,
                'finalized_at' => $n->finalized_at?->toIso8601String(),
                'intact' => $n->intact(),
                'mine' => $n->author_id === $user->id,
            ]),
            'movements' => $file->movements()->with(['from:id,name', 'to:id,name', 'fromDepartment:id,code', 'toDepartment:id,code', 'note:id,number'])->orderByDesc('id')->get()
                ->map(fn (EFileMovement $m) => [
                    'id' => $m->id, 'from' => $m->from->name, 'to' => $m->to->name, 'from_department' => $m->fromDepartment?->code, 'to_department' => $m->toDepartment?->code,
                    'action' => EFileMovement::ACTIONS[$m->required_action], 'reason' => $m->reason, 'note' => $m->note?->number, 'status' => $m->status,
                    'sent_at' => $m->sent_at->toIso8601String(), 'acknowledged_at' => $m->acknowledged_at?->toIso8601String(), 'remarks' => $m->acknowledgement_remarks,
                ]),
            'documents' => Document::query()->where(['attachable_type' => 'efile', 'attachable_id' => $file->id])->orderBy('id')->get()
                ->map(fn ($d) => [...$d->toSummary(), 'replaces' => $d->previous_version_id, 'db_id' => $d->id]),
            'correspondence' => $file->correspondence()->orderBy('register_date')->get()
                ->map(fn ($c) => [...$c->only('id', 'register_number', 'direction', 'kind', 'subject', 'counterparty', 'their_reference'), 'register_date' => $c->register_date->toDateString(),
                    'documents' => Document::query()->where(['attachable_type' => 'correspondence', 'attachable_id' => $c->id])->get()->map->toSummary()]),
            'links' => $file->links()->with('linkable')->get()->map(fn ($l) => [
                'id' => $l->id, 'relation' => $l->relation, 'type' => $l->linkable_type,
                'label' => $l->linkable instanceof WorkflowInstance ? "{$l->linkable->reference} · {$l->linkable->title}" : ($l->linkable instanceof EFile ? $l->linkable->file_number : '—'),
                'status' => $l->linkable->status ?? null,
                'url' => $l->linkable instanceof WorkflowInstance ? route('requests.show', $l->linkable_id) : ($l->linkable instanceof EFile ? route('efiles.show', $l->linkable_id) : null),
            ]),
            'grants' => $file->grants()->inForce()->with('user:id,name')->get()->map(fn (EFileAccessGrant $g) => ['id' => $g->id, 'user' => $g->user->name, 'reason' => $g->reason, 'expires_at' => $g->expires_at?->toIso8601String()]),
            'history' => EFileEvent::query()->where('efile_id', $file->id)->with('actor:id,name')->orderBy('id')->get()
                ->map(fn ($e) => ['id' => $e->id, 'event' => $e->event, 'summary' => $e->summary, 'actor' => $e->actor?->name, 'at' => $e->created_at->toIso8601String()]),
        ];
    }

    // ---- Notes -------------------------------------------------------------

    public function storeNote(Request $request, EFile $efile): RedirectResponse
    {
        $data = $this->noteData($request);
        $note = $this->files->draftNote($efile, $request->user(), $data);
        if ($request->boolean('finalize')) {
            $this->files->finalizeNote($note, $request->user());

            return back()->with('success', 'Note finalised.');
        }

        return back()->with('success', 'Draft saved. Only you can see it until you finalise it.');
    }

    public function updateNote(Request $request, EFileNote $note): RedirectResponse
    {
        $this->files->updateDraft($note, $request->user(), $this->noteData($request));
        if ($request->boolean('finalize')) {
            $this->files->finalizeNote($note, $request->user());

            return back()->with('success', 'Note finalised.');
        }

        return back()->with('success', 'Draft saved.');
    }

    public function destroyNote(Request $request, EFileNote $note): RedirectResponse
    {
        $this->files->deleteDraft($note, $request->user());

        return back()->with('success', 'Draft discarded.');
    }

    private function noteData(Request $request): array
    {
        return $request->validate([
            'kind' => ['nullable', Rule::in(EFileNote::KINDS)],
            'body' => ['required', 'string', 'max:20000'],
            'corrects_note_id' => ['nullable', 'integer'],
            'references' => ['nullable', 'array'],
            'references.notes' => ['nullable', 'array'],
            'references.notes.*' => ['integer'],
        ]);
    }

    // ---- Movement, documents, closure --------------------------------------

    public function send(Request $request, EFile $efile): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'exists:users,id'],
            'required_action' => ['required', Rule::in(array_keys(EFileMovement::ACTIONS))],
            'reason' => ['nullable', 'string', 'max:2000'],
            'note_id' => ['nullable', 'integer'],
        ]);
        $to = User::query()->findOrFail($data['to']);
        $this->files->send($efile, $request->user(), $to, $data['required_action'], $data['reason'] ?? null, $data['note_id'] ?? null);

        return redirect()->route('efiles.index')->with('success', "File sent to {$to->name}. It stays in transit until they acknowledge it.");
    }

    public function receive(Request $request, EFileMovement $movement): RedirectResponse
    {
        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:2000']]);
        $this->files->receive($movement, $request->user(), $data['remarks'] ?? null);

        return redirect()->route('efiles.show', $movement->efile_id)->with('success', 'Received. The file is now with you.');
    }

    public function recall(Request $request, EFileMovement $movement): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $this->files->recall($movement, $request->user(), $data['reason'] ?? null);

        return back()->with('success', 'File recalled.');
    }

    public function attach(Request $request, EFile $efile): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file'],
            'category' => ['nullable', Rule::in(['enclosure', 'draft', 'order', 'scan', 'other'])],
            'replaces' => ['nullable', 'integer'],
        ]);
        $replaces = isset($data['replaces']) ? Document::query()->find($data['replaces']) : null;
        $this->files->attach($efile, $request->user(), $request->file('file'), $data['category'] ?? 'enclosure', $replaces);

        return back()->with('success', 'Document added to the file.');
    }

    public function close(Request $request, EFile $efile): RedirectResponse
    {
        $data = $request->validate(['remarks' => ['required', 'string', 'max:2000']]);
        $this->files->close($efile, $request->user(), $data['remarks']);

        return back()->with('success', 'File closed.');
    }

    public function reopen(Request $request, EFile $efile): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->files->reopen($efile, $request->user(), $data['reason']);

        return back()->with('success', 'File reopened.');
    }

    public function grant(Request $request, EFile $efile): RedirectResponse
    {
        abort_unless($this->access->allows($request->user(), $efile), 403);
        $data = $request->validate(['user_id' => ['required', 'exists:users,id'], 'reason' => ['required', 'string', 'max:1000'], 'expires_at' => ['nullable', 'date', 'after:now']]);
        $this->files->grant($efile, $request->user(), User::query()->findOrFail($data['user_id']), $data['reason'], $data['expires_at'] ?? null);

        return back()->with('success', 'Read access granted.');
    }

    public function revokeGrant(Request $request, EFileAccessGrant $grant): RedirectResponse
    {
        $this->files->revokeGrant($grant, $request->user());

        return back()->with('success', 'Access revoked.');
    }

    public function link(Request $request, EFile $efile): RedirectResponse
    {
        $data = $request->validate(['file_number' => ['required', 'string', 'exists:efiles,file_number'], 'relation' => ['nullable', Rule::in(['linked', 'references', 'part_file'])]]);
        abort_unless($this->access->canAct($request->user(), $efile), 409, 'The file must be with you.');
        $other = EFile::query()->where('file_number', $data['file_number'])->firstOrFail();
        abort_unless($this->access->allows($request->user(), $other), 403, 'You cannot see that file.');
        abort_if($other->is($efile), 422, 'A file cannot be linked to itself.');
        $this->files->link($efile, $request->user(), $other, $data['relation'] ?? 'linked');

        return back()->with('success', 'Files linked.');
    }
}
