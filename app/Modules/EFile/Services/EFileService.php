<?php

namespace App\Modules\EFile\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Documents\DocumentStore;
use App\Modules\Documents\Models\Document;
use App\Modules\EFile\Models\EFile;
use App\Modules\EFile\Models\EFileAccessGrant;
use App\Modules\EFile\Models\EFileEvent;
use App\Modules\EFile\Models\EFileLink;
use App\Modules\EFile\Models\EFileMovement;
use App\Modules\EFile\Models\EFileNote;
use App\Modules\EFile\Models\FileCategory;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\NotificationDispatcher;
use App\Modules\Workflow\Engine\WorkflowException;
use App\Support\SequenceGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Every change to an electronic file (Brief §10). Each command locks the
 * file row, checks custody, writes the change, a file-history event and an
 * audit event in one transaction.
 */
class EFileService
{
    public function __construct(
        private FileAccess $access,
        private SequenceGenerator $sequences,
        private DocumentStore $documents,
        private NotificationDispatcher $notifications,
        private AuditLogger $audit,
    ) {}

    // ---- Opening -----------------------------------------------------------

    public function open(array $data, User $user): EFile
    {
        $category = FileCategory::query()->where('is_active', true)->findOrFail($data['file_category_id']);
        $department = Department::query()->findOrFail($data['department_id'] ?? $user->department_id ?? 0);
        $mine = $user->department_id ? Department::query()->find($user->department_id)?->descendantIds() ?? [] : [];
        if (! in_array($department->id, $mine, true) && ! $user->can('efiles.records')) {
            throw new WorkflowException('You can open files only for your own department.', 403, ['department_id' => 'Not your department.']);
        }

        return DB::transaction(function () use ($data, $user, $category, $department) {
            $pattern = str_replace(['{DEPT}', '{CAT}'], [$department->code, $category->code], $category->numbering_pattern);
            $number = $this->sequences->format("FILE:{$department->code}:{$category->code}", $pattern);

            $file = new EFile;
            $file->forceFill([
                'file_number' => $number,
                'subject' => $data['subject'],
                'description' => $data['description'] ?? null,
                'department_id' => $department->id,
                'file_category_id' => $category->id,
                'classification' => $data['classification'] ?? $category->default_classification,
                'status' => 'open',
                'opened_by' => $user->id,
                'custodian_id' => $user->id,
                'retention_schedule_id' => $category->retention_schedule_id,
            ])->save();

            $this->event($file, 'opened', $user, "File opened: {$file->subject}", ['classification' => $file->classification]);
            $this->audit->record('efile.opened', $file, ['number' => $number, 'classification' => $file->classification], actor: $user);

            return $file;
        });
    }

    // ---- Note sheet --------------------------------------------------------

    public function draftNote(EFile $file, User $user, array $data): EFileNote
    {
        return $this->onFile($file, function (EFile $file) use ($user, $data) {
            $this->mustAct($file, $user);
            $corrects = $this->correctedNote($file, $data);

            $note = new EFileNote;
            $note->forceFill([
                'efile_id' => $file->id,
                'kind' => $corrects ? 'addendum' : ($data['kind'] ?? 'note'),
                'body' => trim($data['body']),
                'author_id' => $user->id,
                'author_name' => $user->name,
                'status' => 'draft',
                'corrects_note_id' => $corrects?->id,
                'references' => $data['references'] ?? null,
            ])->save();

            return $note;
        });
    }

    public function updateDraft(EFileNote $note, User $user, array $data): EFileNote
    {
        if ($note->status !== 'draft' || $note->author_id !== $user->id) {
            throw new WorkflowException('Only the author can edit a draft note.', 403);
        }
        $note->forceFill([
            'body' => trim($data['body']),
            'kind' => $note->corrects_note_id ? 'addendum' : ($data['kind'] ?? $note->kind),
            'references' => $data['references'] ?? $note->references,
        ])->save();

        return $note;
    }

    public function deleteDraft(EFileNote $note, User $user): void
    {
        if ($note->status !== 'draft' || $note->author_id !== $user->id) {
            throw new WorkflowException('Only the author can discard a draft note.', 403);
        }
        $note->delete();
    }

    /** Number, sign off and freeze a note (FILE-002). */
    public function finalizeNote(EFileNote $note, User $user): EFileNote
    {
        return $this->onFile($note->efile_id, function (EFile $file) use ($note, $user) {
            $note = EFileNote::query()->lockForUpdate()->findOrFail($note->id);
            if ($note->status !== 'draft' || $note->author_id !== $user->id) {
                throw new WorkflowException('Only the author can finalise a draft note.', 403);
            }
            $this->mustAct($file, $user);
            if (trim($note->body) === '') {
                throw new WorkflowException('An empty note cannot be finalised.', 422);
            }

            $number = $file->note_count + 1;
            $at = now()->startOfSecond();
            $user->loadMissing('designation');
            $note->forceFill([
                'number' => $number,
                'status' => 'final',
                'author_name' => $user->name,
                'author_designation' => $user->designation?->name,
                'finalized_at' => $at,
                'content_hash' => EFileNote::hashOf($file->id, $number, $note->kind, $note->body, $user->id, $at->format('Y-m-d\TH:i:s'), $note->corrects_note_id),
            ])->save();
            $file->forceFill(['note_count' => $number])->save();

            $label = $note->kind === 'addendum' ? "Addendum to note {$note->corrects()->value('number')}" : ucfirst($note->kind);
            $this->event($file, $note->kind === 'decision' ? 'decision_recorded' : 'note_finalised', $user, "{$label} #{$number} by {$user->name}", ['note' => $number, 'kind' => $note->kind]);
            $this->audit->record('efile.note_finalised', $file, ['note' => $number, 'kind' => $note->kind, 'hash' => $note->content_hash], actor: $user);

            return $note;
        });
    }

    // ---- Movement ----------------------------------------------------------

    public function send(EFile $file, User $user, User $to, string $action, ?string $reason, ?int $noteId): EFileMovement
    {
        return $this->onFile($file, function (EFile $file) use ($user, $to, $action, $reason, $noteId) {
            $this->mustAct($file, $user);
            if ($to->is($user) || ! $to->canSignIn() || ! in_array($to->category, ['faculty', 'staff'], true)) {
                throw new WorkflowException('Send the file to an active member of staff or faculty other than yourself.', 422, ['to' => 'Choose another officer.']);
            }
            if (! array_key_exists($action, EFileMovement::ACTIONS)) {
                throw new WorkflowException('Unknown required action.', 422);
            }
            if ($noteId && ! $file->notes()->whereKey($noteId)->where('status', 'final')->exists()) {
                throw new WorkflowException('Refer to a finalised note of this file.', 422, ['note_id' => 'Unknown note.']);
            }

            $movement = new EFileMovement;
            $movement->forceFill([
                'efile_id' => $file->id,
                'from_user_id' => $user->id,
                'from_department_id' => $user->department_id,
                'to_user_id' => $to->id,
                'to_department_id' => $to->department_id,
                'required_action' => $action,
                'reason' => $reason,
                'note_id' => $noteId,
                'status' => 'in_transit',
                'sent_at' => now(),
            ])->save();

            $this->event($file, 'sent', $user, "Sent to {$to->name} — ".EFileMovement::ACTIONS[$action], ['to' => $to->id, 'action' => $action, 'movement' => $movement->id]);
            $this->audit->record('efile.sent', $file, ['to' => $to->id, 'action' => $action], actor: $user);
            $this->notify('efile.received', $to, $file, ['action' => EFileMovement::ACTIONS[$action], 'sender' => $user->name, 'reason' => (string) $reason], "efile-move:{$movement->id}");

            return $movement;
        });
    }

    /** The receiver acknowledges; custody passes to them (FILE-003). */
    public function receive(EFileMovement $movement, User $user, ?string $remarks = null): EFile
    {
        return $this->onFile($movement->efile_id, function (EFile $file) use ($movement, $user, $remarks) {
            $movement = EFileMovement::query()->lockForUpdate()->findOrFail($movement->id);
            if ($movement->status !== 'in_transit' || $movement->to_user_id !== $user->id) {
                throw new WorkflowException('This file is not waiting for you.', 409);
            }
            $movement->forceFill(['status' => 'received', 'acknowledged_at' => now(), 'acknowledgement_remarks' => $remarks])->save();
            $file->forceFill(['custodian_id' => $user->id])->save();

            $this->event($file, 'received', $user, "Received by {$user->name}", ['movement' => $movement->id]);
            $this->audit->record('efile.received', $file, ['movement' => $movement->id], actor: $user);

            return $file;
        });
    }

    public function recall(EFileMovement $movement, User $user, ?string $reason = null): EFile
    {
        return $this->onFile($movement->efile_id, function (EFile $file) use ($movement, $user, $reason) {
            $movement = EFileMovement::query()->lockForUpdate()->findOrFail($movement->id);
            if ($movement->status !== 'in_transit' || $movement->from_user_id !== $user->id) {
                throw new WorkflowException('Only the sender can recall a file that has not been received.', 409);
            }
            $movement->forceFill(['status' => 'recalled', 'recalled_at' => now(), 'acknowledgement_remarks' => $reason])->save();
            $this->event($file, 'recalled', $user, 'Recalled by '.$user->name, ['movement' => $movement->id]);
            $this->audit->record('efile.recalled', $file, ['movement' => $movement->id], actor: $user);

            return $file;
        });
    }

    // ---- Documents, links, access -----------------------------------------

    public function attach(EFile $file, User $user, UploadedFile $upload, string $category = 'enclosure', ?Document $replaces = null): Document
    {
        return $this->onFile($file, function (EFile $file) use ($user, $upload, $category, $replaces) {
            $this->mustAct($file, $user);
            if ($replaces && ($replaces->attachable_type !== 'efile' || $replaces->attachable_id !== $file->id)) {
                throw new WorkflowException('That document is not on this file.', 422);
            }
            $document = $this->documents->store($upload, $file, $user, $category, $this->documentClass($file->classification), $replaces);
            $this->event($file, 'document_added', $user, ($replaces ? "New version (v{$document->version}) of " : 'Document added: ').$document->original_name, ['document' => $document->uuid]);

            return $document;
        });
    }

    public function link(EFile $file, User $user, Model $target, string $relation = 'linked'): void
    {
        DB::transaction(function () use ($file, $user, $target, $relation) {
            $exists = EFileLink::query()->where(['efile_id' => $file->id, 'linkable_type' => $target->getMorphClass(), 'linkable_id' => $target->getKey()])->exists();
            if ($exists) {
                return;
            }
            (new EFileLink)->forceFill(['efile_id' => $file->id, 'linkable_type' => $target->getMorphClass(), 'linkable_id' => $target->getKey(), 'relation' => $relation, 'added_by' => $user->id])->save();
            $label = $target instanceof EFile ? "file {$target->file_number}" : (($target->reference ?? null) ? "request {$target->reference}" : $target->getMorphClass());
            $this->event($file, 'linked', $user, "Linked {$label}", ['type' => $target->getMorphClass(), 'id' => $target->getKey(), 'relation' => $relation]);
        });
    }

    public function grant(EFile $file, User $by, User $to, string $reason, ?string $expiresAt): EFileAccessGrant
    {
        return $this->onFile($file, function (EFile $file) use ($by, $to, $reason, $expiresAt) {
            if ($file->custodian_id !== $by->id && $file->opened_by !== $by->id) {
                throw new WorkflowException('Only the custodian or the officer who opened the file can grant access.', 403);
            }
            $grant = new EFileAccessGrant;
            $grant->forceFill(['efile_id' => $file->id, 'user_id' => $to->id, 'granted_by' => $by->id, 'reason' => $reason, 'expires_at' => $expiresAt])->save();
            $this->event($file, 'access_granted', $by, "Read access granted to {$to->name}", ['user' => $to->id, 'expires_at' => $expiresAt]);
            $this->audit->record('efile.access_granted', $file, ['user' => $to->id, 'reason' => $reason, 'expires_at' => $expiresAt], actor: $by);

            return $grant;
        });
    }

    public function revokeGrant(EFileAccessGrant $grant, User $by): void
    {
        $this->onFile($grant->efile_id, function (EFile $file) use ($grant, $by) {
            if ($file->custodian_id !== $by->id && $file->opened_by !== $by->id && $grant->granted_by !== $by->id) {
                throw new WorkflowException('You may not revoke this access.', 403);
            }
            $grant->forceFill(['revoked_at' => now()])->save();
            $this->event($file, 'access_revoked', $by, 'Read access revoked from '.$grant->user()->value('name'), ['user' => $grant->user_id]);
            $this->audit->record('efile.access_revoked', $file, ['user' => $grant->user_id], actor: $by);
        });
    }

    // ---- Closing and retention --------------------------------------------

    public function close(EFile $file, User $user, string $remarks): EFile
    {
        return $this->onFile($file, function (EFile $file) use ($user, $remarks) {
            $this->mustAct($file, $user);
            if ($file->notes()->where('status', 'draft')->exists()) {
                throw new WorkflowException('Finalise or discard the draft notes first.', 422);
            }
            $years = $file->retention()->value('retain_years');
            $file->forceFill([
                'status' => 'closed', 'closed_at' => now(), 'closed_by' => $user->id, 'closure_remarks' => $remarks,
                'retain_until' => $years === null ? null : now()->addYears($years)->toDateString(),
            ])->save();
            $this->event($file, 'closed', $user, 'File closed'.($file->retain_until ? ", retained until {$file->retain_until->toDateString()}" : ', retained permanently'), ['remarks' => $remarks]);
            $this->audit->record('efile.closed', $file, ['retain_until' => $file->retain_until?->toDateString()], actor: $user);

            return $file;
        });
    }

    public function reopen(EFile $file, User $user, string $reason): EFile
    {
        return $this->onFile($file, function (EFile $file) use ($user, $reason) {
            if ($file->status !== 'closed' || ($file->custodian_id !== $user->id && ! $user->can('efiles.records'))) {
                throw new WorkflowException('Only the custodian or a records officer can reopen a closed file.', 403);
            }
            $file->forceFill(['status' => 'open', 'closed_at' => null, 'closed_by' => null, 'retain_until' => null, 'disposition' => null])->save();
            $this->event($file, 'reopened', $user, 'File reopened', ['reason' => $reason]);
            $this->audit->record('efile.reopened', $file, ['reason' => $reason], actor: $user);

            return $file;
        });
    }

    public function setLegalHold(EFile $file, User $user, bool $hold, string $reason): EFile
    {
        return $this->onFile($file, function (EFile $file) use ($user, $hold, $reason) {
            $file->forceFill(['legal_hold' => $hold, 'disposition' => $hold && $file->disposition === 'due' ? 'retained' : $file->disposition])->save();
            $this->event($file, $hold ? 'legal_hold_set' : 'legal_hold_lifted', $user, $hold ? 'Legal hold placed' : 'Legal hold lifted', ['reason' => $reason]);
            $this->audit->record($hold ? 'efile.legal_hold_set' : 'efile.legal_hold_lifted', $file, ['reason' => $reason], actor: $user);

            return $file;
        });
    }

    /**
     * Records officer's decision on a file due for disposition:
     * destroy (contents erased, record of destruction kept), transfer
     * (archived), or retain for more years.
     */
    public function dispose(EFile $file, User $user, string $decision, string $reason, ?int $extendYears = null): EFile
    {
        $erase = [];
        $file = $this->onFile($file, function (EFile $file) use ($user, $decision, $reason, $extendYears, &$erase) {
            if ($file->status !== 'closed') {
                throw new WorkflowException('Only closed files can be disposed of.', 409);
            }
            if ($file->legal_hold) {
                throw new WorkflowException('The file is under legal hold.', 409);
            }
            if ($decision !== 'retain' && ($file->retain_until === null || $file->retain_until->isFuture())) {
                throw new WorkflowException('The retention period has not ended.', 409);
            }

            if ($decision === 'retain') {
                $file->forceFill(['retain_until' => now()->addYears(max(1, (int) $extendYears))->toDateString(), 'disposition' => 'retained'])->save();
            } elseif ($decision === 'transfer') {
                $file->forceFill(['status' => 'archived', 'disposition' => 'transferred', 'disposed_at' => now()])->save();
            } elseif ($decision === 'destroy') {
                // Status first: the note trigger permits deleting notes of destroyed files only.
                $file->forceFill(['status' => 'destroyed', 'disposition' => 'destroyed', 'disposed_at' => now(), 'description' => null])->save();
                $docs = Document::query()->where(fn ($q) => $q->where(['attachable_type' => 'efile', 'attachable_id' => $file->id])
                    ->orWhere(fn ($q) => $q->where('attachable_type', 'correspondence')->whereIn('attachable_id', $file->correspondence()->pluck('id'))))->get();
                $erase = $docs->map(fn ($d) => [$d->disk, $d->path])->all();
                Document::query()->whereKey($docs->pluck('id'))->update(['scan_status' => 'destroyed', 'updated_at' => now()]);
                EFileNote::query()->where('efile_id', $file->id)->delete();
            } else {
                throw new WorkflowException('Unknown disposition.', 422);
            }

            $this->event($file, 'disposition', $user, match ($decision) {
                'retain' => "Retention extended to {$file->retain_until->toDateString()}",
                'transfer' => 'Transferred to archives',
                'destroy' => 'Destroyed under retention schedule '.($file->retention()->value('code') ?? '—').' ('.count($erase).' documents erased)',
            }, ['decision' => $decision, 'reason' => $reason]);
            $this->audit->record("efile.disposition.{$decision}", $file, ['reason' => $reason, 'documents' => count($erase)], actor: $user);

            return $file;
        });

        // Erase blobs only once the decision is committed.
        foreach ($erase as [$disk, $path]) {
            try {
                Storage::disk($disk)->delete($path);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $file;
    }

    // ---- Helpers -----------------------------------------------------------

    public function event(EFile $file, string $event, ?User $actor, string $summary, array $data = []): void
    {
        (new EFileEvent)->forceFill(['efile_id' => $file->id, 'event' => $event, 'actor_id' => $actor?->id, 'summary' => mb_substr($summary, 0, 1000), 'data' => $data ?: null])->save();
    }

    private function mustAct(EFile $file, User $user): void
    {
        if (! $this->access->canAct($user, $file)) {
            $why = ! $file->isOpen() ? 'The file is '.$file->status.'.' : ($file->custodian_id !== $user->id ? 'The file is not with you.' : 'The file is in transit.');
            throw new WorkflowException($why, 409);
        }
    }

    private function correctedNote(EFile $file, array $data): ?EFileNote
    {
        if (empty($data['corrects_note_id'])) {
            return null;
        }

        return $file->notes()->whereKey($data['corrects_note_id'])->where('status', 'final')->first()
            ?? throw new WorkflowException('An addendum must refer to a finalised note of this file.', 422);
    }

    private function documentClass(string $fileClass): string
    {
        return $fileClass; // documents inherit the file's classification
    }

    /** Run $fn with the file row locked. */
    private function onFile(EFile|int $file, callable $fn): mixed
    {
        $id = $file instanceof EFile ? $file->id : $file;

        return DB::transaction(fn () => $fn(EFile::query()->lockForUpdate()->findOrFail($id)));
    }

    private function notify(string $template, User $to, EFile $file, array $extra, string $key): void
    {
        try {
            $this->notifications->send($template, [$to], ['file' => ['number' => $file->file_number, 'subject' => $file->isSensitive() ? '(classified)' : $file->subject]] + $extra, $key, route('efiles.show', $file->id, false));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
