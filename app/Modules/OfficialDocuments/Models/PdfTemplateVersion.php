<?php

namespace App\Modules\OfficialDocuments\Models;

use App\Contracts\HasDocuments;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A version of an official template: the original file (never modified),
 * its normalised working copy, detected fields and the field mapping.
 * Once published, nothing about it changes except being superseded.
 */
class PdfTemplateVersion extends Model implements HasDocuments
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['pages' => 'array', 'form_fields' => 'array', 'mapping' => 'array', 'published_at' => 'datetime', 'version' => 'integer', 'page_count' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $v) {
            if ($v->getOriginal('status') !== 'draft' && array_diff(array_keys($v->getDirty()), ['status', 'updated_at']) !== []) {
                throw new LogicException('A published template version cannot be changed. Create a new version.');
            }
        });
        static::deleting(fn () => throw new LogicException('Template versions are kept for the record.'));
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PdfTemplate::class, 'pdf_template_id');
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'original_document_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** The supplied file: template administrators only. */
    public function documentsViewableBy(User $user): bool
    {
        return $user->can(Permissions::OFFICIAL_TEMPLATES_MANAGE);
    }
}
