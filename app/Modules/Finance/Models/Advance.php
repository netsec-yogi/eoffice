<?php

namespace App\Modules\Finance\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Advance extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount_requested' => 'float', 'amount_sanctioned' => 'float', 'spent' => 'float', 'balance' => 'float', 'settle_by' => 'date', 'settled_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'disbursed' && $this->settle_by !== null && $this->settle_by->isPast();
    }
}
