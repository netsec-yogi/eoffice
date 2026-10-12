<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Identity\Models\Committee;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowTask extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'assigned_via' => 'array',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'voting_weight' => 'decimal:2',
            'escalation_level' => 'integer',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(WorkflowToken::class, 'workflow_token_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function principal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'principal_id');
    }

    public function committee(): BelongsTo
    {
        return $this->belongsTo(Committee::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(WorkflowTaskEvent::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isOverdue(): bool
    {
        return $this->isPending() && $this->due_at !== null && $this->due_at->isPast();
    }
}
