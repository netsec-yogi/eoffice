<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named office workflows route to (HOD, DEAN_ACADEMIC, REGISTRAR ...).
 * Department-scoped authorities have one holder per department.
 */
#[Fillable(['code', 'name', 'scope', 'description', 'is_active'])]
class Authority extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AuthorityAssignment::class);
    }

    public function isDepartmentScoped(): bool
    {
        return $this->scope === 'department';
    }
}
