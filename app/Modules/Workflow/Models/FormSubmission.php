<?php

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Model;

class FormSubmission extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['data' => 'array', 'submitted_at' => 'datetime'];
    }
}
