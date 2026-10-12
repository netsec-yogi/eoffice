<?php

namespace App\Modules\Audit;

use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one way to write an audit event.
 *
 * Events form a hash chain: hash = sha256(prev_hash . canonical(event)).
 * Writers serialise on a transaction-scoped advisory lock so the chain
 * stays linear. When called inside a business transaction the event
 * commits or rolls back with it -- an action that did not happen leaves
 * no record that it did.
 */
class AuditLogger
{
    private const LOCK_KEY = 7310021; // arbitrary, unique to the audit chain

    /**
     * @param  array<string, mixed>  $context  never put secrets here; known secret keys are redacted anyway
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $context = [],
        string $outcome = 'success',
        ?User $actor = null,
        ?User $onBehalfOf = null,
    ): AuditEvent {
        $actor ??= Auth::user();
        $request = app()->runningInConsole() ? null : request();

        $attributes = [
            'occurred_at' => CarbonImmutable::now('UTC'),
            'actor_id' => $actor?->getKey(),
            'actor_label' => $actor ? Str::limit($actor->name.' <'.$actor->username.'>', 250) : (app()->runningInConsole() ? 'system' : null),
            'on_behalf_of_id' => $onBehalfOf?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'outcome' => $outcome,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'correlation_id' => Context::get('correlation_id'),
            'context' => $context === [] ? null : self::normalise(self::redact($context)),
        ];

        return DB::transaction(function () use ($attributes) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
            }
            $prev = DB::table('audit_events')->orderByDesc('id')->value('hash');
            $attributes['prev_hash'] = $prev;
            $attributes['hash'] = self::hash($attributes, $prev);

            $event = new AuditEvent;
            $event->forceFill($attributes)->save();

            return $event;
        });
    }

    /** @param array<string, mixed> $event */
    public static function hash(array $event, ?string $prevHash): string
    {
        $occurred = $event['occurred_at'];
        $occurred = $occurred instanceof CarbonInterface ? $occurred : CarbonImmutable::parse($occurred);

        $payload = [
            'occurred_at' => $occurred->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'actor_id' => $event['actor_id'] === null ? null : (int) $event['actor_id'],
            'on_behalf_of_id' => $event['on_behalf_of_id'] === null ? null : (int) $event['on_behalf_of_id'],
            'action' => $event['action'],
            'subject_type' => $event['subject_type'],
            'subject_id' => $event['subject_id'] === null ? null : (int) $event['subject_id'],
            'outcome' => $event['outcome'],
            'correlation_id' => $event['correlation_id'],
            'context' => is_string($event['context']) ? json_decode($event['context'], true) : $event['context'],
        ];

        return hash('sha256', ($prevHash ?? '').'|'.json_encode(self::canonical($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function redact(array $context): array
    {
        $needles = config('audit.redact', []);
        foreach ($context as $key => $value) {
            if (is_string($key) && Str::contains(strtolower($key), $needles)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = self::redact($value);
            }
        }

        return $context;
    }

    /**
     * Make the context survive a JSONB round trip unchanged: scalars only,
     * floats as strings (JSONB may reformat them), objects stringified.
     */
    private static function normalise(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map(self::normalise(...), $value),
            is_float($value) => (string) $value,
            $value instanceof CarbonInterface => $value->toIso8601String(),
            $value instanceof \BackedEnum => $value->value,
            is_object($value) => method_exists($value, '__toString') ? (string) $value : get_class($value),
            default => $value,
        };
    }

    /** Recursively sort keys of associative arrays so encoding is order-independent. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
