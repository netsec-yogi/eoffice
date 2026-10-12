<?php

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class WorkflowTransition extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    public const OUTCOMES = ['default', 'approve', 'reject', 'success', 'failure'];

    protected function casts(): array
    {
        return ['condition' => 'array', 'priority' => 'integer'];
    }

    protected static function booted(): void
    {
        $guard = function (self $t) {
            if (WorkflowVersion::query()->whereKey($t->workflow_version_id)->value('status') !== 'draft') {
                throw new LogicException('Transitions of a published workflow version cannot be changed.');
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }
}
