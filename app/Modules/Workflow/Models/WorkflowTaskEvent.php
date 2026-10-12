<?php

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowTaskEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['recipients' => 'array', 'fired_at' => 'datetime'];
    }
}
