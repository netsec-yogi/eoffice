<?php

namespace App\Modules\Documents\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Written only by DocumentStore. */
class Document extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'version' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return array<string, mixed> */
    public function toSummary(): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'category' => $this->category,
            'classification' => $this->classification,
            'scan_status' => $this->scan_status,
            'version' => $this->version,
            'uploaded_at' => $this->created_at?->toIso8601String(),
            'url' => route('documents.show', $this->uuid),
            'preview' => in_array($this->mime_type, config('documents.inline'), true),
        ];
    }
}
