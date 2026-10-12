<?php

namespace App\Modules\OfficialDocuments\Models;

use App\Contracts\HasDocuments;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An official PDF generated for an approved request (DOC-004). The file is
 * stored through DocumentStore; it is served only by
 * OfficialDocumentController::download, which checks release and access
 * and records every download.
 */
class OfficialDocument extends Model implements HasDocuments
{
    /** Statuses in which the document can be downloaded (subject to access). */
    public const RELEASED = ['generated'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'generated_at' => 'datetime', 'attempts' => 'integer', 'document_version' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function output(): BelongsTo
    {
        return $this->belongsTo(WorkflowDocumentOutput::class, 'workflow_document_output_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(PdfTemplateVersion::class, 'pdf_template_version_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** Never through the generic document route: see the class comment. */
    public function documentsViewableBy(User $user): bool
    {
        return false;
    }
}
