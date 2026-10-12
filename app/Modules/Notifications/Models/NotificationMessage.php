<?php

namespace App\Modules\Notifications\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message to one recipient on one channel. Written by NotificationDispatcher. */
class NotificationMessage extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'read_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
