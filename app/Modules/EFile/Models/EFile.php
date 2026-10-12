<?php

namespace App\Modules\EFile\Models;

use App\Contracts\HasDocuments;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Gate;

/** An electronic file. All changes go through EFileService. */
class EFile extends Model implements HasDocuments
{
    protected $table = 'efiles';

    protected $guarded = ['*'];

    public const CLASSIFICATIONS = ['public', 'internal', 'confidential', 'restricted'];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'retain_until' => 'date',
            'disposed_at' => 'datetime',
            'legal_hold' => 'boolean',
            'note_count' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FileCategory::class, 'file_category_id');
    }

    public function retention(): BelongsTo
    {
        return $this->belongsTo(RetentionSchedule::class, 'retention_schedule_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(EFileNote::class, 'efile_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(EFileMovement::class, 'efile_id');
    }

    public function inTransit(): HasOne
    {
        return $this->hasOne(EFileMovement::class, 'efile_id')->where('status', 'in_transit');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EFileEvent::class, 'efile_id');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(EFileAccessGrant::class, 'efile_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(EFileLink::class, 'efile_id');
    }

    public function correspondence(): HasMany
    {
        return $this->hasMany(Correspondence::class, 'efile_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isSensitive(): bool
    {
        return in_array($this->classification, ['confidential', 'restricted'], true);
    }

    public function documentsViewableBy(User $user): bool
    {
        return Gate::forUser($user)->allows('view', $this);
    }
}
