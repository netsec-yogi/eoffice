<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'level', 'is_active'])]
class Designation extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'level' => 'integer'];
    }
}
