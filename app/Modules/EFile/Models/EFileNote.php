<?php

namespace App\Modules\EFile\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One note on the note sheet. Drafts are private to their author;
 * finalised notes are numbered, hashed and immutable (model guard here,
 * database trigger underneath). Corrections are addenda.
 */
class EFileNote extends Model
{
    protected $table = 'efile_notes';

    protected $guarded = ['*'];

    public const KINDS = ['note', 'observation', 'recommendation', 'decision', 'clarification', 'addendum'];

    protected function casts(): array
    {
        return ['references' => 'array', 'finalized_at' => 'datetime', 'number' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $n) {
            if ($n->getOriginal('status') === 'final') {
                throw new LogicException('A finalised note cannot be changed. Add an addendum.');
            }
        });
        static::deleting(function (self $n) {
            if ($n->status === 'final') {
                throw new LogicException('A finalised note cannot be deleted.');
            }
        });
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_note_id');
    }

    public static function hashOf(int $fileId, int $number, string $kind, string $body, int $authorId, string $finalizedAt, ?int $corrects): string
    {
        return hash('sha256', implode('|', [$fileId, $number, $kind, $authorId, $finalizedAt, $corrects ?? '', $body]));
    }

    /** Recompute the hash and compare (integrity check shown in the UI). */
    public function intact(): bool
    {
        return $this->status !== 'final' || hash_equals((string) $this->content_hash, self::hashOf(
            $this->efile_id, $this->number, $this->kind, $this->body, $this->author_id, $this->finalized_at->format('Y-m-d\TH:i:s'), $this->corrects_note_id,
        ));
    }
}
