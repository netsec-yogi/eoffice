<?php

namespace App\Modules\ComputerCentre\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenceAllocation extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['allocated_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function title(): BelongsTo
    {
        return $this->belongsTo(SoftwareTitle::class, 'software_title_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
