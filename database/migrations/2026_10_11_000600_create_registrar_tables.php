<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- Leave (LEAVE-001) ----------------------------------------------
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('has_balance')->default(true);       // duty / station leave: false
            $table->boolean('counts_holidays')->default(false);  // true: calendar days; false: working days only
            $table->boolean('allow_half_day')->default(false);
            $table->decimal('max_days_per_application', 5, 1)->nullable();
            $table->unsignedSmallInteger('min_notice_days')->nullable();
            $table->decimal('certificate_after_days', 5, 1)->nullable(); // supporting document needed beyond this
            $table->jsonb('eligible_categories')->nullable();    // null = all staff and faculty
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('leave_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('leave_type_id')->constrained();
            $table->foreignId('workflow_instance_id')->unique()->constrained();
            $table->date('from_date');
            $table->date('to_date');
            $table->boolean('half_day')->default(false);
            $table->decimal('days', 5, 1);
            $table->string('status', 16)->default('pending');     // pending, approved, rejected, cancelled, curtailed
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'from_date']);
        });

        // The balance is the sum of the ledger; nothing else stores it.
        Schema::create('leave_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('leave_type_id')->constrained();
            $table->string('kind', 16);                           // credit, debit, reversal, adjustment, lapse
            $table->decimal('days', 6, 1);                        // signed
            $table->foreignId('leave_record_id')->nullable()->constrained();
            $table->foreignId('workflow_instance_id')->nullable()->constrained();
            $table->string('reason');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at');

            $table->index(['user_id', 'leave_type_id']);
            // A workflow step can post each kind of entry once (idempotent replays).
            $table->unique(['leave_record_id', 'kind', 'workflow_instance_id']);
        });

        // ---- Travel and claims (REG-001) ------------------------------------
        Schema::create('travel_authorisations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('kind', 8);                            // tour, ltc
            $table->foreignId('workflow_instance_id')->unique()->constrained();
            $table->string('purpose', 500);
            $table->string('destination');
            $table->date('from_date');
            $table->date('to_date');
            $table->string('block', 16)->nullable();              // LTC block
            $table->jsonb('travellers')->nullable();              // LTC dependants
            $table->decimal('advance_requested', 12, 2)->default(0);
            $table->decimal('advance_sanctioned', 12, 2)->nullable();
            $table->string('status', 16)->default('pending');     // pending, approved, rejected, cancelled
            $table->string('claim_status', 16)->default('none');  // none, claimed, settled
            $table->timestamps();

            $table->index(['user_id', 'kind', 'status']);
        });

        Schema::create('reimbursement_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('kind', 16);                           // tour, ltc, medical
            $table->foreignId('workflow_instance_id')->unique()->constrained();
            $table->foreignId('travel_authorisation_id')->nullable()->constrained();
            $table->decimal('claimed_amount', 12, 2);
            $table->decimal('admissible_amount', 12, 2)->nullable();
            $table->decimal('advance_adjusted', 12, 2)->default(0);
            $table->decimal('net_payable', 12, 2)->nullable();    // negative = to be recovered
            $table->string('status', 16)->default('pending');     // pending, sanctioned, paid, rejected, cancelled
            $table->string('payment_reference')->nullable();
            $table->date('paid_on')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'kind', 'status']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX reimbursement_one_live_claim ON reimbursement_claims (travel_authorisation_id) WHERE status NOT IN ('rejected', 'cancelled')");
        }

        // ---- Certificates and NOCs (REG-002) --------------------------------
        Schema::create('certificate_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('title');
            $table->text('body');                                 // {{ placeholders }}
            $table->string('numbering_pattern', 100);
            $table->text('footer')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->string('number', 100)->unique();
            $table->foreignId('certificate_template_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('workflow_instance_id')->nullable()->constrained();
            $table->text('body');                                 // rendered text at issue
            $table->string('signatory_name');
            $table->string('signatory_designation')->nullable();
            $table->timestamp('issued_at');
            $table->string('verification_code', 32)->unique();
            $table->foreignId('document_id')->nullable()->constrained('documents');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();
            $table->timestamps();

            $table->unique(['workflow_instance_id', 'certificate_template_id']);
        });

        // ---- Circulars and office orders (REG-003) --------------------------
        Schema::create('circulars', function (Blueprint $table) {
            $table->id();
            $table->string('number', 100)->nullable()->unique();  // assigned at publication
            $table->string('kind', 16);                           // circular, office_order, notice
            $table->string('title', 500);
            $table->text('body');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('previous_version_id')->nullable()->constrained('circulars');
            $table->string('status', 16)->default('draft');       // draft, in_review, published, superseded, withdrawn, archived
            $table->foreignId('author_id')->constrained('users');
            $table->foreignId('department_id')->nullable()->constrained();
            $table->jsonb('audience');                            // {"all": true} | {"departments": [ids], "categories": [...]}
            $table->boolean('requires_ack')->default(false);
            $table->date('ack_by')->nullable();
            $table->foreignId('workflow_instance_id')->nullable()->constrained();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users');
            $table->foreignId('document_id')->nullable()->constrained('documents');
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });

        Schema::create('circular_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circular_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->timestamp('delivered_at');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();

            $table->unique(['circular_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });

        // ---- RTI (REG-004) --------------------------------------------------
        Schema::create('rti_requests', function (Blueprint $table) {
            $table->id();
            $table->string('registration_number', 64)->unique();
            $table->foreignId('workflow_instance_id')->unique()->constrained();
            $table->string('applicant_name');
            $table->text('applicant_address')->nullable();
            $table->string('applicant_email')->nullable();
            $table->string('applicant_phone', 32)->nullable();
            $table->boolean('is_bpl')->default(false);
            $table->boolean('fee_paid')->default(false);
            $table->date('received_on');
            $table->string('received_via', 16);
            $table->boolean('life_liberty')->default(false);
            $table->string('subject', 500);
            $table->foreignId('pio_id')->nullable()->constrained('users');
            $table->date('due_on');
            $table->string('status', 16)->default('open');        // open, transferred, replied
            $table->string('transferred_to')->nullable();
            $table->date('transferred_on')->nullable();
            $table->date('replied_on')->nullable();
            $table->string('outcome', 16)->nullable();            // provided, partly, denied, transferred
            $table->string('appeal_status', 16)->default('none'); // none, filed, decided
            $table->date('appeal_received_on')->nullable();
            $table->date('appeal_due_on')->nullable();
            $table->date('appeal_decided_on')->nullable();
            $table->foreignId('appeal_instance_id')->nullable()->constrained('workflow_instances');
            $table->timestamps();

            $table->index(['status', 'due_on']);
        });
    }

    public function down(): void
    {
        foreach (['rti_requests', 'circular_receipts', 'circulars', 'certificates', 'certificate_templates', 'reimbursement_claims', 'travel_authorisations', 'leave_ledger', 'leave_records', 'leave_types'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
