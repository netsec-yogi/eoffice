<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Identity\Models\Department;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['key', 'name', 'description', 'department_id'])]
class FormTemplate extends Model
{
    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class)->orderByDesc('version');
    }

    public function latestPublished(): HasOne
    {
        return $this->hasOne(FormVersion::class)->ofMany(['version' => 'max'], fn ($q) => $q->where('status', 'published'));
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
