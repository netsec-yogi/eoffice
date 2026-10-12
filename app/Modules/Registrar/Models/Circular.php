<?php

namespace App\Modules\Registrar\Models;

use App\Contracts\HasDocuments;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Circular extends Model implements HasDocuments
{
    protected $guarded = ['*'];

    public const KINDS = ['circular' => 'Circular', 'office_order' => 'Office order', 'notice' => 'Notice'];

    protected function casts(): array
    {
        return ['audience' => 'array', 'requires_ack' => 'boolean', 'ack_by' => 'date', 'published_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(CircularReceipt::class);
    }

    public function documentsViewableBy(User $user): bool
    {
        return $this->author_id === $user->id || $user->can('circulars.manage')
            || ($this->published_at !== null && $this->receipts()->where('user_id', $user->id)->exists());
    }
}
