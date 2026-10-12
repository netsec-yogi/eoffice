<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- Audit: append-only, hash-chained (docs/SECURITY.md R-1) -------
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('occurred_at', 6);
            // No foreign keys: deleting a user must never touch audit rows.
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_label')->nullable();
            $table->unsignedBigInteger('on_behalf_of_id')->nullable();
            $table->string('action', 100)->index();
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('outcome', 16)->default('success'); // success, failure, denied
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->jsonb('context')->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64)->unique();

            $table->index(['subject_type', 'subject_id']);
            $table->index('occurred_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION audit_events_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_events is append-only (% rejected)', TG_OP;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER audit_events_no_update BEFORE UPDATE ON audit_events
                    FOR EACH ROW EXECUTE FUNCTION audit_events_append_only();
                CREATE TRIGGER audit_events_no_delete BEFORE DELETE ON audit_events
                    FOR EACH ROW EXECUTE FUNCTION audit_events_append_only();
                CREATE TRIGGER audit_events_no_truncate BEFORE TRUNCATE ON audit_events
                    FOR EACH STATEMENT EXECUTE FUNCTION audit_events_append_only();
            SQL);
        }

        // --- Gap-free reference numbers (file numbers, request numbers) ----
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 100);   // e.g. "WF:IT-EMAIL", "FILE:CSE"
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->unique(['scope', 'year']);
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->jsonb('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // --- Documents (private storage, served only via DocumentController)
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->nullableMorphs('attachable');
            $table->string('category', 64)->default('attachment');
            $table->string('disk', 32);
            $table->string('path')->unique();
            $table->string('original_name');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            // public, internal, confidential, restricted
            $table->string('classification', 16)->default('internal');
            // pending, clean, infected, unscanned, failed
            $table->string('scan_status', 16)->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('previous_version_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('sha256');
        });

        // --- Notifications -------------------------------------------------
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100);
            $table->string('channel', 16); // mail, in_app, sms, whatsapp
            $table->string('subject')->nullable();
            $table->text('body');
            // Provider-registered template id (WhatsApp/DLT SMS need one).
            $table->string('provider_template_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['key', 'channel']);
        });

        Schema::create('notification_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('template_key', 100);
            $table->string('channel', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient')->nullable(); // address/number at send time
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('link')->nullable();
            $table->string('status', 16)->default('queued'); // queued, sent, failed, skipped
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable(); // in-app only
            $table->timestamps();

            $table->index(['user_id', 'channel', 'read_at']);
            $table->index(['status', 'channel']);
        });

        // --- Calendars -----------------------------------------------------
        Schema::create('business_calendars', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->jsonb('working_days'); // ISO weekdays, 1 = Monday
            $table->time('day_starts_at');
            $table->time('day_ends_at');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_calendar_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name');
            $table->string('kind', 16)->default('gazetted'); // gazetted, restricted, local
            $table->timestamps();

            $table->unique(['business_calendar_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('business_calendars');
        Schema::dropIfExists('notification_messages');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('sequences');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TABLE IF EXISTS audit_events; DROP FUNCTION IF EXISTS audit_events_append_only();');
        } else {
            Schema::dropIfExists('audit_events');
        }
    }
};
