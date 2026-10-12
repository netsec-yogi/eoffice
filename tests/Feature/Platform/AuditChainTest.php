<?php

namespace Tests\Feature\Platform;

use App\Modules\Audit\AuditLogger;
use App\Modules\Audit\Models\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** SEC-001, SEC-002 */
class AuditChainTest extends TestCase
{
    public function test_events_form_a_verifiable_chain(): void
    {
        $audit = app(AuditLogger::class);
        $audit->record('test.one', context: ['amount' => 1500.75, 'nested' => ['b' => 1, 'a' => [3, 2]]]);
        $audit->record('test.two', context: ['ünïcode' => 'देवनागरी']);
        $audit->record('test.three');

        $events = AuditEvent::query()->orderBy('id')->get();
        $this->assertNull($events[0]->prev_hash);
        $this->assertSame($events[0]->hash, $events[1]->prev_hash);
        $this->artisan('audit:verify')->assertSuccessful();
    }

    public function test_database_rejects_updates_and_deletes(): void
    {
        $event = app(AuditLogger::class)->record('test.one');

        $this->expectException(QueryException::class);
        DB::table('audit_events')->where('id', $event->id)->update(['action' => 'tampered']);
    }

    public function test_database_rejects_deletes(): void
    {
        $event = app(AuditLogger::class)->record('test.one');

        $this->expectException(QueryException::class);
        DB::table('audit_events')->where('id', $event->id)->delete();
    }

    public function test_tampering_by_a_privileged_operator_is_detected(): void
    {
        $audit = app(AuditLogger::class);
        $audit->record('test.one', context: ['decision' => 'reject']);
        $target = $audit->record('test.two', context: ['decision' => 'reject']);
        $audit->record('test.three');

        // Someone with ALTER rights disables the trigger and rewrites history (R-1).
        DB::statement('ALTER TABLE audit_events DISABLE TRIGGER USER');
        DB::table('audit_events')->where('id', $target->id)->update(['context' => json_encode(['decision' => 'approve'])]);
        DB::statement('ALTER TABLE audit_events ENABLE TRIGGER USER');

        $this->artisan('audit:verify')->assertFailed()->expectsOutputToContain("#{$target->id}");
    }

    public function test_secret_keys_are_redacted(): void
    {
        $event = app(AuditLogger::class)->record('test.secret', context: [
            'password' => 'hunter2', 'nested' => ['api_key' => 'abc', 'otp_code' => '123456', 'ok' => 'kept'],
        ]);

        $this->assertSame('[redacted]', $event->context['password']);
        $this->assertSame('[redacted]', $event->context['nested']['api_key']);
        $this->assertSame('[redacted]', $event->context['nested']['otp_code']);
        $this->assertSame('kept', $event->context['nested']['ok']);
        $this->assertStringNotContainsString('hunter2', json_encode(DB::table('audit_events')->get()));
    }

    public function test_model_refuses_updates(): void
    {
        $event = app(AuditLogger::class)->record('test.one');
        $this->expectException(\LogicException::class);
        $event->forceFill(['action' => 'x'])->save();
    }
}
