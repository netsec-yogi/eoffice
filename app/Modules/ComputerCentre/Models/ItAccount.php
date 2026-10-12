<?php

namespace App\Modules\ComputerCentre\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One granted access (mailbox, Wi-Fi, VPN, VM). Written by AccountLedger. */
class ItAccount extends Model
{
    protected $guarded = ['*'];

    public const KINDS = ['email', 'wifi', 'vpn', 'vm'];

    protected function casts(): array
    {
        return ['details' => 'array', 'expires_on' => 'date', 'provisioned_at' => 'datetime', 'deprovisioned_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
