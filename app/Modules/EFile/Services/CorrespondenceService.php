<?php

namespace App\Modules\EFile\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Documents\DocumentStore;
use App\Modules\EFile\Models\Correspondence;
use App\Modules\EFile\Models\EFile;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\NotificationDispatcher;
use App\Modules\Workflow\Engine\WorkflowException;
use App\Support\SequenceGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Diary (incoming) and dispatch (outgoing) registers (FILE-004). */
class CorrespondenceService
{
    public function __construct(
        private SequenceGenerator $sequences,
        private DocumentStore $documents,
        private FileAccess $access,
        private EFileService $files,
        private NotificationDispatcher $notifications,
        private AuditLogger $audit,
    ) {}

    /** @param list<UploadedFile> $uploads */
    public function register(array $data, User $user, array $uploads = []): Correspondence
    {
        $file = ! empty($data['efile_id']) ? EFile::query()->findOrFail($data['efile_id']) : null;
        // Outgoing letters are dispatched from a file the sender holds; incoming
        // ones are registered by the registry (or by a custodian for their file).
        if ($data['direction'] === 'outgoing' && (! $file || ! $this->access->canAct($user, $file))) {
            throw new WorkflowException('Dispatch outgoing letters from a file that is with you.', 422, ['efile_id' => 'Choose a file in your custody.']);
        }
        if ($data['direction'] === 'incoming' && ! $user->can('efiles.correspondence') && ! ($file && $this->access->canAct($user, $file))) {
            throw new WorkflowException('Only the registry can diarise incoming correspondence.', 403);
        }

        $entry = DB::transaction(function () use ($data, $user, $uploads, $file) {
            $prefix = $data['direction'] === 'incoming' ? 'DIARY' : 'DISPATCH';
            $entry = new Correspondence;
            $entry->forceFill([
                'register_number' => $this->sequences->format("CORR:{$prefix}", "{$prefix}/{YYYY}/{SEQ:5}"),
                'direction' => $data['direction'],
                'kind' => $data['kind'],
                'subject' => $data['subject'],
                'counterparty' => $data['counterparty'],
                'their_reference' => $data['their_reference'] ?? null,
                'letter_date' => $data['letter_date'] ?? null,
                'register_date' => $data['register_date'] ?? now()->toDateString(),
                'mode' => $data['mode'],
                'classification' => $data['classification'] ?? $file?->classification ?? 'internal',
                'efile_id' => $file?->id,
                'marked_to' => $data['marked_to'] ?? null,
                'registered_by' => $user->id,
                'remarks' => $data['remarks'] ?? null,
            ])->save();
            foreach ($uploads as $upload) {
                $this->documents->store($upload, $entry, $user, 'correspondence', $entry->classification);
            }
            if ($file) {
                $this->files->event($file, 'correspondence', $user, ucfirst($entry->direction)." {$entry->kind} {$entry->register_number} ".($entry->direction === 'incoming' ? 'from' : 'to')." {$entry->counterparty}", ['correspondence' => $entry->id]);
            }
            $this->audit->record('correspondence.registered', $entry, ['number' => $entry->register_number, 'direction' => $entry->direction, 'efile' => $file?->file_number], actor: $user);

            return $entry;
        });

        if ($entry->marked_to && $entry->marked_to !== $user->id) {
            $this->notifyMarked($entry);
        }

        return $entry;
    }

    /** Put a diarised letter on a file the user holds. */
    public function attachToFile(Correspondence $entry, EFile $file, User $user): void
    {
        if (! $this->access->canAct($user, $file)) {
            throw new WorkflowException('The file must be with you.', 409);
        }
        if ($entry->efile_id) {
            throw new WorkflowException("Already on file {$entry->file()->value('file_number')}.", 409);
        }
        if ($entry->marked_to !== $user->id && $entry->registered_by !== $user->id && ! $user->can('efiles.correspondence')) {
            throw new WorkflowException('This correspondence is not marked to you.', 403);
        }
        DB::transaction(function () use ($entry, $file, $user) {
            $entry->forceFill(['efile_id' => $file->id])->save();
            $this->files->event($file, 'correspondence', $user, "Placed on file: {$entry->direction} {$entry->kind} {$entry->register_number} ({$entry->counterparty})", ['correspondence' => $entry->id]);
            $this->audit->record('correspondence.filed', $entry, ['efile' => $file->file_number], actor: $user);
        });
    }

    private function notifyMarked(Correspondence $entry): void
    {
        try {
            $this->notifications->send('correspondence.marked', [User::query()->findOrFail($entry->marked_to)], ['entry' => [
                'number' => $entry->register_number, 'kind' => $entry->kind, 'from' => $entry->counterparty,
                'subject' => in_array($entry->classification, ['confidential', 'restricted'], true) ? '(classified)' : $entry->subject,
            ]], "corr:{$entry->id}:marked", route('correspondence.index', ['q' => $entry->register_number], false));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
