<?php

namespace App\Modules\Audit\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One audit record. Rows are only ever inserted by AuditLogger; the model
 * refuses updates and deletes, and PostgreSQL triggers refuse them too.
 */
class AuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    // Microseconds and offset are part of the hashed payload; the default
    // format would drop them on write and break verification.
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'context' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit events are append-only.'));
    }
}
