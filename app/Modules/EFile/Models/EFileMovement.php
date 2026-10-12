<?php

namespace App\Modules\EFile\Models;

use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EFileMovement extends Model
{
    protected $table = 'efile_movements';

    protected $guarded = ['*'];

    public const ACTIONS = [
        'comments' => 'For comments',
        'approval' => 'For approval',
        'information' => 'For information',
        'action' => 'For necessary action',
        'signature' => 'For signature',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'acknowledged_at' => 'datetime', 'recalled_at' => 'datetime'];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(EFile::class, 'efile_id');
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function fromDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'from_department_id');
    }

    public function toDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(EFileNote::class, 'note_id');
    }
}
