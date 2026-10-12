<?php

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Where execution currently is. Parallel branches have one token each. */
class WorkflowToken extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['wake_at' => 'datetime'];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_token_id');
    }
}
