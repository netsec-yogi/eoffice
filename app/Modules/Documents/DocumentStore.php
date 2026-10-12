<?php

namespace App\Modules\Documents;

use App\Modules\Audit\AuditLogger;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Scanning\MalwareScanner;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only way a file enters the system (SEC-004): content-type sniffing
 * against an allow-list, size cap, random storage name on a private disk,
 * SHA-256, malware scan. Original names are kept for display only.
 */
class DocumentStore
{
    public function __construct(private MalwareScanner $scanner, private AuditLogger $audit) {}

    public function store(
        UploadedFile $file,
        ?Model $attachable,
        User $uploader,
        string $category = 'attachment',
        string $classification = 'internal',
        ?Document $replaces = null,
        string $field = 'file',
    ): Document {
        $this->validate($file, $field);
        if (! in_array($classification, config('documents.classifications'), true)) {
            throw ValidationException::withMessages([$field => 'Unknown classification.']);
        }

        $disk = config('documents.disk');
        $uuid = (string) Str::uuid();
        $path = now()->format('Y/m').'/'.$uuid;
        Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));
        $absolute = Storage::disk($disk)->path($path);

        $scan = $this->scanner->scan($absolute);
        if ($scan === 'infected') {
            Storage::disk($disk)->delete($path);
            $this->audit->record('document.rejected_malware', $attachable, ['name' => $this->safeName($file)], outcome: 'denied', actor: $uploader);
            throw ValidationException::withMessages([$field => 'The file was rejected by the malware scanner.']);
        }

        $document = new Document;
        $document->forceFill([
            'uuid' => $uuid,
            'attachable_type' => $attachable?->getMorphClass(),
            'attachable_id' => $attachable?->getKey(),
            'category' => $category,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $this->safeName($file),
            'mime_type' => $this->sniff($file),
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $absolute),
            'classification' => $classification,
            'scan_status' => $scan,
            'version' => $replaces ? $replaces->version + 1 : 1,
            'previous_version_id' => $replaces?->id,
            'uploaded_by' => $uploader->id,
        ])->save();

        $this->audit->record('document.uploaded', $document, [
            'name' => $document->original_name, 'sha256' => $document->sha256, 'version' => $document->version,
            'attachable' => $attachable ? $attachable->getMorphClass().':'.$attachable->getKey() : null,
        ], actor: $uploader);

        return $document;
    }

    /** Attach documents uploaded before their owner existed (e.g. with a form submission). */
    public function attach(Document $document, Model $attachable): void
    {
        if ($document->attachable_id !== null) {
            return;
        }
        $document->forceFill(['attachable_type' => $attachable->getMorphClass(), 'attachable_id' => $attachable->getKey()])->save();
    }

    public function validate(UploadedFile $file, string $field = 'file'): void
    {
        $allowed = config('documents.allowed');
        $mime = $this->sniff($file);
        $extension = strtolower($file->getClientOriginalExtension());

        if (! $file->isValid()) {
            throw ValidationException::withMessages([$field => 'The upload failed.']);
        }
        if ($file->getSize() > config('documents.max_kb') * 1024) {
            throw ValidationException::withMessages([$field => 'The file is larger than '.config('documents.max_kb').' KB.']);
        }
        if (! isset($allowed[$mime]) || ! in_array($extension, $allowed[$mime], true)) {
            throw ValidationException::withMessages([$field => 'This file type is not allowed.']);
        }
    }

    /** Type from the bytes (libmagic), never from the client's claim or the extension. */
    private function sniff(UploadedFile $file): string
    {
        return (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
    }

    private function safeName(UploadedFile $file): string
    {
        $name = preg_replace('/[^\pL\pN ._()-]+/u', '_', $file->getClientOriginalName()) ?: 'file';

        return Str::limit(trim($name, ' .'), 200, '');
    }
}
