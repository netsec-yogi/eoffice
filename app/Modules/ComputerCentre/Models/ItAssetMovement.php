<?php

namespace App\Modules\ComputerCentre\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItAssetMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_user_id');
    }
}
