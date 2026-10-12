<?php

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use LogicException;

/**
 * One version of a workflow graph. Drafts are edited by the designer;
 * published versions never change (WF-002) -- instances started on them run
 * to completion on exactly this graph.
 */
class WorkflowVersion extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'published_at' => 'datetime', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $v) {
            $frozen = $v->getOriginal('status') !== 'draft';
            if ($frozen && $v->isDirty(['settings', 'form_version_id', 'checksum'])) {
                throw new LogicException('A published workflow version cannot be changed. Create a new version.');
            }
        });
        static::deleting(function (self $v) {
            if ($v->status !== 'draft') {
                throw new LogicException('Only draft workflow versions can be deleted.');
            }
        });
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(WorkflowNode::class);
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class)->orderBy('priority')->orderBy('id');
    }

    public function node(string $key): WorkflowNode
    {
        return $this->nodes->firstWhere('key', $key) ?? throw new LogicException("Node [{$key}] not in version {$this->id}.");
    }

    public function startNode(): WorkflowNode
    {
        return $this->nodes->firstWhere('type', 'start') ?? throw new LogicException("Version {$this->id} has no start node.");
    }

    /** @return Collection<int, WorkflowTransition> */
    public function outgoing(string $nodeKey)
    {
        return $this->transitions->where('from_node', $nodeKey)->values();
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }
}
