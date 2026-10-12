<?php

namespace App\Modules\EFile\Http;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\Document;
use App\Modules\EFile\Models\Correspondence;
use App\Modules\EFile\Models\EFile;
use App\Modules\EFile\Services\CorrespondenceService;
use App\Modules\EFile\Services\FileAccess;
use App\Modules\Identity\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CorrespondenceController extends Controller
{
    public function __construct(private CorrespondenceService $service, private FileAccess $access) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $f = $request->validate(['direction' => ['nullable', Rule::in(['incoming', 'outgoing'])], 'q' => ['nullable', 'string', 'max:200'], 'mine' => ['nullable', 'boolean']]);
        $registry = $user->can(Permissions::EFILES_CORRESPONDENCE);

        $entries = Correspondence::query()->with(['file:id,file_number', 'markedTo:id,name', 'registeredBy:id,name'])
            // Visibility mirrors EFilePolicy::viewCorrespondence.
            ->where(function ($q) use ($user, $registry) {
                $q->where('registered_by', $user->id)->orWhere('marked_to', $user->id)
                    ->orWhereIn('efile_id', $this->access->scope(EFile::query(), $user)->select('efiles.id'));
                if ($registry) {
                    $q->orWhereNotIn('classification', ['confidential', 'restricted']);
                }
            })
            ->when($f['direction'] ?? null, fn ($q, $d) => $q->where('direction', $d))
            ->when($f['mine'] ?? false, fn ($q) => $q->where('marked_to', $user->id)->whereNull('efile_id'))
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($q) => $q->where('register_number', 'ilike', "%{$t}%")->orWhere('subject', 'ilike', "%{$t}%")->orWhere('counterparty', 'ilike', "%{$t}%")->orWhere('their_reference', 'ilike', "%{$t}%")))
            ->orderByDesc('register_date')->orderByDesc('id')->paginate(25)->withQueryString()
            ->through(fn (Correspondence $c) => [
                ...$c->only('id', 'register_number', 'direction', 'kind', 'subject', 'counterparty', 'their_reference', 'mode', 'classification'),
                'register_date' => $c->register_date->toDateString(),
                'letter_date' => $c->letter_date?->toDateString(),
                'file' => $c->file?->only('id', 'file_number'),
                'marked_to' => $c->markedTo?->name,
                'mine' => $c->marked_to === $user->id,
                'documents' => Document::query()->where(['attachable_type' => 'correspondence', 'attachable_id' => $c->id])->get()->map->toSummary(),
            ]);

        return Inertia::render('Correspondence/Index', [
            'entries' => $entries,
            'filters' => $f,
            'registry' => $registry,
            'myFiles' => EFile::query()->where('custodian_id', $user->id)->where('status', 'open')->whereDoesntHave('inTransit')->orderByDesc('updated_at')->get(['id', 'file_number', 'subject']),
            'kinds' => Correspondence::KINDS,
            'modes' => Correspondence::MODES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(['incoming', 'outgoing'])],
            'kind' => ['required', Rule::in(Correspondence::KINDS)],
            'subject' => ['required', 'string', 'max:500'],
            'counterparty' => ['required', 'string', 'max:255'],
            'their_reference' => ['nullable', 'string', 'max:255'],
            'letter_date' => ['nullable', 'date'],
            'register_date' => ['nullable', 'date'],
            'mode' => ['required', Rule::in(Correspondence::MODES)],
            'classification' => ['nullable', Rule::in(['public', 'internal', 'confidential', 'restricted'])],
            'efile_id' => ['nullable', 'exists:efiles,id'],
            'marked_to' => ['nullable', 'exists:users,id'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'documents' => ['nullable', 'array', 'max:10'],
            'documents.*' => ['file'],
        ]);
        $entry = $this->service->register($data, $request->user(), $request->file('documents', []));

        return back()->with('success', "Registered as {$entry->register_number}.");
    }

    public function attach(Request $request, Correspondence $entry): RedirectResponse
    {
        $data = $request->validate(['efile_id' => ['required', 'exists:efiles,id']]);
        $this->service->attachToFile($entry, EFile::query()->findOrFail($data['efile_id']), $request->user());

        return back()->with('success', 'Placed on the file.');
    }
}
