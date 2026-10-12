<?php

namespace App\Modules\Registrar\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRecord extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date', 'half_day' => 'boolean', 'days' => 'float', 'approved_at' => 'datetime'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
