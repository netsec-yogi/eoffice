<?php

namespace App\Modules\Registrar\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelAuthorisation extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date', 'travellers' => 'array', 'advance_requested' => 'float', 'advance_sanctioned' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
