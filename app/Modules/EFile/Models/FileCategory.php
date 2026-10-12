<?php

namespace App\Modules\EFile\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['code', 'name', 'description', 'numbering_pattern', 'default_classification', 'retention_schedule_id', 'is_active'])]
class FileCategory extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function retention(): BelongsTo
    {
        return $this->belongsTo(RetentionSchedule::class, 'retention_schedule_id');
    }
}
