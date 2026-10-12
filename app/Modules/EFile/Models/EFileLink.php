<?php

namespace App\Modules\EFile\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EFileLink extends Model
{
    protected $table = 'efile_links';

    protected $guarded = ['*'];

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
