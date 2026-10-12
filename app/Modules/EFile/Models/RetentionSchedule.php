<?php

namespace App\Modules\EFile\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'retain_years', 'action', 'description'])]
class RetentionSchedule extends Model
{
    public const ACTIONS = ['review', 'destroy', 'transfer'];

    protected function casts(): array
    {
        return ['retain_years' => 'integer'];
    }
}
