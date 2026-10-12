<?php

namespace App\Modules\Workflow\Models;

use App\Contracts\HasDocuments;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Gate;

/** A running (or finished) request. State changes only through WorkflowEngine. */
class WorkflowInstance extends Model implements HasDocuments
{
    protected $guarded = ['*'];

    /** WF-013: the only status changes the engine may make. */
    public const TRANSITIONS = [
        'draft' => ['in_progress', 'cancelled'],
        'in_progress' => ['returned', 'on_hold', 'completed', 'rejected', 'cancelled'],
        'returned' => ['in_progress', 'cancelled'],
        'on_hold' => ['in_progress', 'cancelled'],
        'completed' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    public const OPEN = ['in_progress', 'returned', 'on_hold'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'variables' => 'array',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'on_hold_since' => 'datetime',
            'revision' => 'integer',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }

    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(WorkflowToken::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkflowTask::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(WorkflowAction::class)->orderBy('id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class)->orderBy('revision');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'attachable');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function documentsViewableBy(User $user): bool
    {
        return $this->initiator_id === $user->id || Gate::forUser($user)->allows('view', $this);
    }
}
