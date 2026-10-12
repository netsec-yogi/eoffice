<?php

namespace App\Modules\Registrar\Http;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Permissions;
use App\Modules\Registrar\Models\Circular;
use App\Modules\Registrar\Models\CircularReceipt;
use App\Modules\Registrar\Services\CircularService;
use App\Modules\Workflow\Models\WorkflowDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CircularController extends Controller
{
    public function __construct(private CircularService $circulars) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $f = $request->validate(['tab' => ['nullable', Rule::in(['inbox', 'pending_ack', 'drafts', 'all'])], 'q' => ['nullable', 'string', 'max:200']]);
        $tab = $f['tab'] ?? 'inbox';
        $manage = $user->can(Permissions::CIRCULARS_MANAGE);
        abort_if($tab === 'all' && ! $manage, 403);
        abort_if($tab === 'drafts' && ! $user->can(Permissions::CIRCULARS_DRAFT), 403);

        $query = Circular::query()->with('author:id,name');
        match ($tab) {
            'inbox' => $query->whereIn('id', CircularReceipt::query()->where('user_id', $user->id)->select('circular_id'))->whereIn('status', ['published', 'superseded', 'withdrawn']),
            'pending_ack' => $query->where('requires_ack', true)->where('status', 'published')->whereIn('id', CircularReceipt::query()->where('user_id', $user->id)->whereNull('acknowledged_at')->select('circular_id')),
            'drafts' => $query->whereIn('status', ['draft', 'in_review'])->when(! $manage, fn ($q) => $q->where('author_id', $user->id)),
            'all' => $query,
        };
        $receipts = CircularReceipt::query()->where('user_id', $user->id)->get()->keyBy('circular_id');

        return Inertia::render('Circulars/Index', [
            'tab' => $tab,
            'circulars' => $query->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($q) => $q->where('title', 'ilike', "%{$t}%")->orWhere('number', 'ilike', "%{$t}%")))
                ->orderByRaw('published_at desc nulls first')->orderByDesc('id')->paginate(25)->withQueryString()
                ->through(fn (Circular $c) => [
                    ...$c->only('id', 'number', 'kind', 'title', 'status', 'version', 'requires_ack'),
                    'published_at' => $c->published_at?->toIso8601String(), 'author' => $c->author->name,
                    'read' => (bool) $receipts->get($c->id)?->read_at, 'acknowledged' => (bool) $receipts->get($c->id)?->acknowledged_at,
                ]),
            'counts' => [
                'unread' => CircularReceipt::query()->where('user_id', $user->id)->whereNull('read_at')->count(),
                'pending_ack' => CircularReceipt::query()->where('user_id', $user->id)->whereNull('acknowledged_at')->whereHas('circular', fn ($q) => $q->where('requires_ack', true)->where('status', 'published'))->count(),
            ],
            'can' => ['draft' => $user->can(Permissions::CIRCULARS_DRAFT), 'manage' => $manage],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize(Permissions::CIRCULARS_DRAFT);

        return Inertia::render('Circulars/Edit', ['circular' => null, ...$this->formOptions()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::CIRCULARS_DRAFT);
        $c = $this->circulars->draft($this->data($request), $request->user());

        return redirect()->route('circulars.show', $c)->with('success', 'Draft saved.');
    }

    public function edit(Request $request, Circular $circular): Response
    {
        $this->authorize(Permissions::CIRCULARS_DRAFT);
        abort_unless($circular->status === 'draft', 409, 'Only drafts can be edited.');

        return Inertia::render('Circulars/Edit', ['circular' => [...$circular->only('id', 'kind', 'title', 'body', 'requires_ack', 'version'), 'ack_by' => $circular->ack_by?->toDateString(), 'departments' => $circular->audience['departments'] ?? [], 'categories' => $circular->audience['categories'] ?? ['faculty', 'staff']], ...$this->formOptions()]);
    }

    public function update(Request $request, Circular $circular): RedirectResponse
    {
        $this->authorize(Permissions::CIRCULARS_DRAFT);
        $this->circulars->update($circular, $this->data($request), $request->user());

        return redirect()->route('circulars.show', $circular)->with('success', 'Draft saved.');
    }

    public function show(Request $request, Circular $circular): Response
    {
        $user = $request->user();
        $receipt = CircularReceipt::query()->where(['circular_id' => $circular->id, 'user_id' => $user->id])->first();
        $staff = $circular->author_id === $user->id || $user->can(Permissions::CIRCULARS_MANAGE);
        abort_unless($receipt || $staff, 403);
        if ($receipt) {
            $this->circulars->markRead($circular, $user);
        }
        $circular->load('author.designation', 'department');
        $report = null;
        if ($staff && $circular->published_at) {
            $report = CircularReceipt::query()->where('circular_id', $circular->id)->join('users', 'users.id', '=', 'circular_receipts.user_id')
                ->leftJoin('departments', 'departments.id', '=', 'users.department_id')
                ->selectRaw("coalesce(departments.code, '—') as department, count(*) as total, count(read_at) as read, count(acknowledged_at) as acknowledged")
                ->groupBy('departments.code')->orderBy('departments.code')->get();
        }

        return Inertia::render('Circulars/Show', [
            'circular' => [
                ...$circular->only('id', 'number', 'kind', 'title', 'body', 'status', 'version', 'requires_ack', 'workflow_instance_id', 'previous_version_id'),
                'kind_label' => Circular::KINDS[$circular->kind],
                'ack_by' => $circular->ack_by?->toDateString(),
                'published_at' => $circular->published_at?->toIso8601String(),
                'author' => $circular->author->name, 'author_designation' => $circular->author->designation?->name,
                'department' => $circular->department?->name,
                'pdf' => $circular->document_id ? Document::query()->find($circular->document_id)?->toSummary()['url'] : null,
                'superseded_by' => Circular::query()->where('previous_version_id', $circular->id)->where('status', 'published')->value('id'),
            ],
            'receipt' => $receipt ? ['acknowledged_at' => $receipt->acknowledged_at?->toIso8601String()] : null,
            'report' => $report,
            'can' => [
                'edit' => $circular->status === 'draft' && $user->can(Permissions::CIRCULARS_DRAFT) && ($circular->author_id === $user->id || $user->can(Permissions::CIRCULARS_MANAGE)),
                'manage' => $user->can(Permissions::CIRCULARS_MANAGE),
                'revise' => $circular->status === 'published' && $user->can(Permissions::CIRCULARS_DRAFT),
            ],
            'workflows' => WorkflowDefinition::query()->where('category', 'circulars')->where('is_active', true)->whereHas('currentVersion')->get(['key', 'name']),
        ]);
    }

    public function submit(Request $request, Circular $circular): RedirectResponse
    {
        $this->authorize(Permissions::CIRCULARS_DRAFT);
        $data = $request->validate(['workflow' => ['required', 'string', 'exists:workflow_definitions,key']]);
        $this->circulars->submit($circular, $request->user(), $data['workflow']);

        return back()->with('success', 'Sent for approval. It is published automatically once approved.');
    }

    public function acknowledge(Request $request, Circular $circular): RedirectResponse
    {
        abort_unless(CircularReceipt::query()->where(['circular_id' => $circular->id, 'user_id' => $request->user()->id])->exists(), 403);
        $this->circulars->acknowledge($circular, $request->user());

        return back()->with('success', 'Acknowledged.');
    }

    public function revise(Request $request, Circular $circular): RedirectResponse
    {
        $this->authorize(Permissions::CIRCULARS_DRAFT);
        $draft = $this->circulars->revise($circular, $request->user());

        return redirect()->route('circulars.edit', $draft)->with('success', 'New version drafted. The current one stays in force until this is published.');
    }

    public function withdraw(Request $request, Circular $circular): RedirectResponse
    {
        $this->authorize(Permissions::CIRCULARS_MANAGE);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->circulars->withdraw($circular, $request->user(), $data['reason']);

        return back()->with('success', 'Withdrawn.');
    }

    public function archive(Request $request, Circular $circular): RedirectResponse
    {
        $this->authorize(Permissions::CIRCULARS_MANAGE);
        $this->circulars->archive($circular, $request->user());

        return back()->with('success', 'Archived.');
    }

    private function formOptions(): array
    {
        return ['kinds' => Circular::KINDS, 'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])];
    }

    private function data(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', Rule::in(array_keys(Circular::KINDS))],
            'title' => ['required', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:50000'],
            'departments' => ['nullable', 'array'],
            'departments.*' => ['integer', 'exists:departments,id'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => [Rule::in(['faculty', 'staff', 'student'])],
            'requires_ack' => ['boolean'],
            'ack_by' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
    }
}
