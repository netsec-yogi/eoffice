<?php

namespace App\Modules\Calendar\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'working_days', 'day_starts_at', 'day_ends_at', 'is_default'])]
class BusinessCalendar extends Model
{
    protected function casts(): array
    {
        return ['working_days' => 'array', 'is_default' => 'boolean'];
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }
}
