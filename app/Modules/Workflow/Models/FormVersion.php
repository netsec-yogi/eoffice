<?php

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Immutable once published (FORM-004). Written by FormService. */
class FormVersion extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['schema' => 'array', 'published_at' => 'datetime', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $v) {
            if ($v->getOriginal('status') !== 'draft' && $v->isDirty('schema')) {
                throw new LogicException('A published form version cannot be changed. Create a new version.');
            }
        });
        static::deleting(function (self $v) {
            if ($v->status !== 'draft') {
                throw new LogicException('Only draft form versions can be deleted.');
            }
        });
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'form_template_id');
    }
}
