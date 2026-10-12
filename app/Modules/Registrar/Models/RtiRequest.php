<?php

namespace App\Modules\Registrar\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RtiRequest extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'received_on' => 'date', 'due_on' => 'date', 'transferred_on' => 'date', 'replied_on' => 'date',
            'appeal_received_on' => 'date', 'appeal_due_on' => 'date', 'appeal_decided_on' => 'date',
            'is_bpl' => 'boolean', 'fee_paid' => 'boolean', 'life_liberty' => 'boolean',
        ];
    }

    public function pio(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pio_id');
    }
}
