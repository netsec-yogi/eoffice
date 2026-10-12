<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Identity\Models\Department;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['key', 'name', 'description', 'department_id', 'category', 'numbering_pattern', 'is_active'])]
class WorkflowDefinition extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class)->orderByDesc('version');
    }

    /** The version new instances start on. */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(WorkflowVersion::class)->ofMany(['version' => 'max'], fn ($q) => $q->where('status', 'published'));
    }

    public function draftVersion(): HasOne
    {
        return $this->hasOne(WorkflowVersion::class)->ofMany(['version' => 'max'], fn ($q) => $q->where('status', 'draft'));
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
