<?php

namespace App\Modules\Registrar\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Documents\DocumentStore;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\NotificationDispatcher;
use App\Modules\Registrar\Models\Circular;
use App\Modules\Registrar\Models\CircularReceipt;
use App\Modules\Workflow\Engine\WorkflowEngine;
use App\Modules\Workflow\Engine\WorkflowException;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Support\SequenceGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Circulars, office orders and notices (REG-003): draft -> approval through
 * a configured workflow (category "circulars") -> publication with number,
 * PDF and a receipt per recipient -> read receipts and acknowledgments.
 * Published versions are never edited: a revision is a new version that
 * supersedes the old one on publication.
 */
class CircularService
{
    public function __construct(
        private WorkflowEngine $engine,
        private SequenceGenerator $sequences,
        private DocumentStore $documents,
        private NotificationDispatcher $notifications,
        private AuditLogger $audit,
    ) {}

    public function draft(array $data, User $author, ?Circular $revises = null): Circular
    {
        $c = new Circular;
        $c->forceFill([
            'kind' => $data['kind'], 'title' => $data['title'], 'body' => $data['body'],
            'audience' => $this->audience($data), 'requires_ack' => (bool) ($data['requires_ack'] ?? false), 'ack_by' => $data['ack_by'] ?? null,
            'author_id' => $author->id, 'department_id' => $data['department_id'] ?? $author->department_id, 'status' => 'draft',
            'version' => $revises ? $revises->version + 1 : 1, 'previous_version_id' => $revises?->id,
        ])->save();
        $this->audit->record('circular.drafted', $c, ['revises' => $revises?->number], actor: $author);

        return $c;
    }

    public function update(Circular $c, array $data, User $user): Circular
    {
        $this->editable($c, $user);
        $c->forceFill(['kind' => $data['kind'], 'title' => $data['title'], 'body' => $data['body'], 'audience' => $this->audience($data),
            'requires_ack' => (bool) ($data['requires_ack'] ?? false), 'ack_by' => $data['ack_by'] ?? null])->save();

        return $c;
    }

    public function revise(Circular $published, User $user): Circular
    {
        if ($published->status !== 'published') {
            throw new WorkflowException('Only a published circular can be revised.', 409);
        }

        return $this->draft([...$published->only('kind', 'title', 'body', 'requires_ack', 'department_id'), ...$published->audience, 'ack_by' => null], $user, $published);
    }

    public function submit(Circular $c, User $user, string $workflowKey): void
    {
        $this->editable($c, $user);
        $definition = WorkflowDefinition::query()->where('key', $workflowKey)->where('category', 'circulars')->firstOrFail();
        DB::transaction(function () use ($c, $user, $definition) {
            $instance = $this->engine->start($definition, $user, ['title' => $c->title, 'kind' => Circular::KINDS[$c->kind], 'summary' => Str::limit($c->body, 300)], true, $c);
            $c->forceFill(['status' => 'in_review', 'workflow_instance_id' => $instance->id])->save();
        });
    }

    /** Called by the approval workflow's publication step. */
    public function publish(Circular $c, ?User $by = null): Circular
    {
        if ($c->status === 'published') {
            return $c; // replay
        }

        return DB::transaction(function () use ($c, $by) {
            $kindCode = ['circular' => 'CIR', 'office_order' => 'OO', 'notice' => 'NOT'][$c->kind];
            $number = $this->sequences->format("CIRC:{$kindCode}", "{$kindCode}/{YYYY}/{SEQ:3}");
            $c->forceFill(['number' => $number, 'status' => 'published', 'published_at' => now(), 'published_by' => $by?->id])->save();

            $pdf = Pdf::loadView('pdf.circular', ['circular' => $c->load('author.designation', 'department'), 'institute' => config('app.name')])->output();
            $path = tempnam(sys_get_temp_dir(), 'circ');
            file_put_contents($path, $pdf);
            $doc = $this->documents->store(new UploadedFile($path, Str::slug($number).'.pdf', 'application/pdf', null, true), $c, $by ?? $c->author, 'circular', 'internal');
            $c->forceFill(['document_id' => $doc->id])->save();

            if ($c->previous_version_id) {
                Circular::query()->whereKey($c->previous_version_id)->update(['status' => 'superseded', 'updated_at' => now()]);
            }
            $recipients = $this->recipients($c);
            $now = now();
            foreach ($recipients->chunk(500) as $chunk) {
                CircularReceipt::query()->insertOrIgnore($chunk->map(fn ($id) => ['circular_id' => $c->id, 'user_id' => $id, 'delivered_at' => $now])->values()->all());
            }
            $this->audit->record('circular.published', $c, ['number' => $number, 'recipients' => $recipients->count()], actor: $by);
            DB::afterCommit(fn () => $this->notifications->send('circular.published', User::query()->whereKey($recipients->all())->get(),
                ['circular' => ['number' => $number, 'title' => $c->title, 'kind' => Circular::KINDS[$c->kind], 'ack' => $c->requires_ack ? 'Please acknowledge it.' : '']],
                "circular:{$c->id}:published", route('circulars.show', $c->id, false)));

            return $c;
        });
    }

    public function markRead(Circular $c, User $user): void
    {
        CircularReceipt::query()->where(['circular_id' => $c->id, 'user_id' => $user->id])->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function acknowledge(Circular $c, User $user): void
    {
        $updated = CircularReceipt::query()->where(['circular_id' => $c->id, 'user_id' => $user->id])->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now(), 'read_at' => DB::raw('coalesce(read_at, now())')]);
        if ($updated) {
            $this->audit->record('circular.acknowledged', $c, [], actor: $user);
        }
    }

    public function withdraw(Circular $c, User $by, string $reason): void
    {
        if ($c->status !== 'published') {
            throw new WorkflowException('Only published circulars can be withdrawn.', 409);
        }
        $c->forceFill(['status' => 'withdrawn', 'withdrawn_at' => now()])->save();
        $this->audit->record('circular.withdrawn', $c, ['reason' => $reason], actor: $by);
    }

    public function archive(Circular $c, User $by): void
    {
        if (! in_array($c->status, ['published', 'superseded', 'withdrawn'], true)) {
            throw new WorkflowException('Only issued circulars can be archived.', 409);
        }
        $c->forceFill(['status' => 'archived'])->save();
        $this->audit->record('circular.archived', $c, [], actor: $by);
    }

    /** User ids the circular is addressed to: active staff, faculty (and students when targeted). */
    public function recipients(Circular $c)
    {
        $a = $c->audience;
        $departments = collect($a['departments'] ?? [])->flatMap(fn ($id) => Department::query()->find($id)?->descendantIds() ?? [])->unique()->values()->all();

        return User::query()->active()
            ->when(! ($a['all'] ?? false) && $departments, fn ($q) => $q->whereIn('department_id', $departments))
            ->whereIn('category', $a['categories'] ?? ['faculty', 'staff'])
            ->pluck('id');
    }

    private function audience(array $data): array
    {
        $departments = array_values(array_map('intval', $data['departments'] ?? []));

        return ['all' => $departments === [], 'departments' => $departments, 'categories' => array_values($data['categories'] ?? ['faculty', 'staff'])];
    }

    private function editable(Circular $c, User $user): void
    {
        if ($c->status !== 'draft' || ($c->author_id !== $user->id && ! $user->can('circulars.manage'))) {
            throw new WorkflowException('Only a draft can be changed, by its author or the circulars office.', 409);
        }
    }
}
