<?php

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class WorkflowNode extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    public const TYPES = [
        'start', 'approval', 'review', 'verification', 'committee', 'decision',
        'parallel_split', 'parallel_join', 'signature', 'automation', 'notification', 'timer', 'end',
    ];

    /** Nodes that create tasks for people. */
    public const HUMAN = ['approval', 'review', 'verification', 'committee', 'signature'];

    protected function casts(): array
    {
        return ['config' => 'array'];
    }

    protected static function booted(): void
    {
        $guard = function (self $n) {
            if (WorkflowVersion::query()->whereKey($n->workflow_version_id)->value('status') !== 'draft') {
                throw new LogicException('Nodes of a published workflow version cannot be changed.');
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }

    public function isHuman(): bool
    {
        return in_array($this->type, self::HUMAN, true);
    }

    public function cfg(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /** Actions a person may take on this node's tasks. */
    public function allowedActions(): array
    {
        if ($this->type === 'committee') {
            return ['approve', 'reject', 'abstain'];
        }

        return $this->cfg('actions', ['approve', 'reject', 'return']);
    }
}
