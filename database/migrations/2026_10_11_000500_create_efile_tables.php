<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How long closed files are kept, and what happens then (FILE-005).
        Schema::create('retention_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('retain_years')->nullable(); // null = permanent
            $table->string('action', 16)->default('review');          // review, destroy, transfer
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('file_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // Tokens: {DEPT} {CAT} {YYYY} {YY} {SEQ:n}
            $table->string('numbering_pattern', 100)->default('{DEPT}/{CAT}/{YYYY}/{SEQ:4}');
            $table->string('default_classification', 16)->default('internal');
            $table->foreignId('retention_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('efiles', function (Blueprint $table) {
            $table->id();
            $table->string('file_number', 100)->unique();
            $table->string('subject', 500);
            $table->text('description')->nullable();
            $table->foreignId('department_id')->constrained();
            $table->foreignId('file_category_id')->constrained();
            $table->string('classification', 16)->default('internal'); // public, internal, confidential, restricted
            $table->string('status', 16)->default('open');            // open, closed, archived, destroyed
            $table->foreignId('opened_by')->constrained('users');
            $table->foreignId('custodian_id')->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->text('closure_remarks')->nullable();
            $table->foreignId('retention_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->date('retain_until')->nullable();
            $table->string('disposition', 16)->nullable();             // due, retained, destroyed, transferred
            $table->timestamp('disposed_at')->nullable();
            $table->boolean('legal_hold')->default(false);
            $table->unsignedInteger('note_count')->default(0);         // last finalised note number
            $table->timestamps();

            $table->index(['custodian_id', 'status']);
            $table->index(['department_id', 'status']);
            $table->index(['status', 'retain_until']);
        });

        Schema::create('efile_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('efile_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number')->nullable();            // assigned at finalisation
            $table->string('kind', 16)->default('note');               // note, observation, recommendation, decision, clarification, addendum
            $table->text('body');
            $table->foreignId('author_id')->constrained('users');
            $table->string('author_name');                             // snapshot at finalisation
            $table->string('author_designation')->nullable();
            $table->string('status', 8)->default('draft');             // draft, final
            $table->foreignId('corrects_note_id')->nullable()->constrained('efile_notes');
            $table->jsonb('references')->nullable();                   // {"notes": [3], "documents": ["uuid"]}
            $table->char('content_hash', 64)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['efile_id', 'number']);
            $table->index(['efile_id', 'status']);
        });

        Schema::create('efile_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('efile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users');
            $table->foreignId('from_department_id')->nullable()->constrained('departments');
            $table->foreignId('to_user_id')->constrained('users');
            $table->foreignId('to_department_id')->nullable()->constrained('departments');
            $table->string('required_action', 32);                    // comments, approval, information, action, signature
            $table->text('reason')->nullable();
            $table->foreignId('note_id')->nullable()->constrained('efile_notes');
            $table->string('status', 16)->default('in_transit');       // in_transit, received, recalled
            $table->timestamp('sent_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('acknowledgement_remarks')->nullable();
            $table->timestamp('recalled_at')->nullable();
            $table->timestamps();

            $table->index(['to_user_id', 'status']);
            $table->index(['efile_id', 'id']);
        });

        // At most one movement in transit per file.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX efile_movements_one_in_transit ON efile_movements (efile_id) WHERE status = 'in_transit'");
        }

        Schema::create('efile_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('efile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('granted_by')->constrained('users');
            $table->text('reason');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['efile_id', 'user_id']);
        });

        Schema::create('efile_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('efile_id')->constrained()->cascadeOnDelete();
            $table->morphs('linkable');                               // workflow_instance, efile
            $table->string('relation', 32)->default('linked');
            $table->foreignId('added_by')->constrained('users');
            $table->timestamps();

            $table->unique(['efile_id', 'linkable_type', 'linkable_id']);
        });

        // Append-only file history (FILE-006), alongside the audit log.
        Schema::create('efile_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('efile_id')->constrained()->cascadeOnDelete();
            $table->string('event', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->text('summary');
            $table->jsonb('data')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(['efile_id', 'id']);
        });

        Schema::create('correspondence', function (Blueprint $table) {
            $table->id();
            $table->string('register_number', 64)->unique();          // diary (incoming) / dispatch (outgoing) number
            $table->string('direction', 16);                           // incoming, outgoing
            $table->string('kind', 16)->default('letter');             // letter, memo, order, circular, email, other
            $table->string('subject', 500);
            $table->string('counterparty');                            // sender (incoming) or addressee (outgoing)
            $table->string('their_reference')->nullable();
            $table->date('letter_date')->nullable();
            $table->date('register_date');                              // received / dispatched on
            $table->string('mode', 16)->default('post');               // post, email, hand, courier, portal
            $table->string('classification', 16)->default('internal');
            $table->foreignId('efile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('marked_to')->nullable()->constrained('users');
            $table->foreignId('registered_by')->constrained('users');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['direction', 'register_date']);
            $table->index('efile_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Finalised notes never change and are never deleted (FILE-002),
            // whatever the application does. Corrections are addenda.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION efile_notes_final_guard() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        -- Only a file destroyed under its retention schedule may lose its notes.
                        IF OLD.status = 'final' AND (SELECT status FROM efiles WHERE id = OLD.efile_id) <> 'destroyed' THEN
                            RAISE EXCEPTION 'finalised notes cannot be deleted';
                        END IF;
                        RETURN OLD;
                    END IF;
                    IF OLD.status = 'final' THEN
                        RAISE EXCEPTION 'finalised notes cannot be changed';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER efile_notes_final_guard BEFORE UPDATE OR DELETE ON efile_notes
                    FOR EACH ROW EXECUTE FUNCTION efile_notes_final_guard();

                CREATE OR REPLACE FUNCTION efile_events_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'efile_events is append-only';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER efile_events_no_update BEFORE UPDATE OR DELETE ON efile_events
                    FOR EACH ROW EXECUTE FUNCTION efile_events_append_only();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TABLE IF EXISTS efile_events, efile_notes CASCADE; DROP FUNCTION IF EXISTS efile_notes_final_guard(); DROP FUNCTION IF EXISTS efile_events_append_only();');
        }
        foreach (['correspondence', 'efile_events', 'efile_links', 'efile_access_grants', 'efile_movements', 'efile_notes', 'efiles', 'file_categories', 'retention_schedules'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
