<?php

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationJob extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['input' => 'array', 'result' => 'array', 'attempts' => 'integer'];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }
}
