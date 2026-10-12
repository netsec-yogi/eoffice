<?php

namespace App\Modules\EFile\Models;

use App\Contracts\HasDocuments;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;

/** A diary (incoming) or dispatch (outgoing) register entry. */
class Correspondence extends Model implements HasDocuments
{
    protected $table = 'correspondence';

    protected $guarded = ['*'];

    public const KINDS = ['letter', 'memo', 'order', 'circular', 'email', 'other'];

    public const MODES = ['post', 'email', 'hand', 'courier', 'portal'];

    protected function casts(): array
    {
        return ['letter_date' => 'date', 'register_date' => 'date'];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(EFile::class, 'efile_id');
    }

    public function markedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_to');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function documentsViewableBy(User $user): bool
    {
        return Gate::forUser($user)->allows('view', $this);
    }
}
