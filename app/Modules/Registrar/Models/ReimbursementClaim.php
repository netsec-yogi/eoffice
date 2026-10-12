<?php

namespace App\Modules\Registrar\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReimbursementClaim extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['claimed_amount' => 'float', 'admissible_amount' => 'float', 'advance_adjusted' => 'float', 'net_payable' => 'float', 'paid_on' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function authorisation(): BelongsTo
    {
        return $this->belongsTo(TravelAuthorisation::class, 'travel_authorisation_id');
    }
}
