<?php

namespace App\Modules\Calendar\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_calendar_id', 'date', 'name', 'kind'])]
class Holiday extends Model
{
    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendar::class, 'business_calendar_id');
    }
}
