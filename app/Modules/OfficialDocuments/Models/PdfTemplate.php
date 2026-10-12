<?php

namespace App\Modules\OfficialDocuments\Models;

use App\Modules\Identity\Models\Department;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One of the institute's prescribed PDF forms (DOC-001). Files and mappings live in its versions. */
#[Fillable(['key', 'name', 'description', 'department_id'])]
class PdfTemplate extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PdfTemplateVersion::class)->orderByDesc('version');
    }

    /** The version new requests are given. */
    public function published(): HasOne
    {
        return $this->hasOne(PdfTemplateVersion::class)->where('status', 'published');
    }

    public function draft(): HasOne
    {
        return $this->hasOne(PdfTemplateVersion::class)->where('status', 'draft');
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(WorkflowDocumentOutput::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
