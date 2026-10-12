<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- Forms (FORM-004: templates are versioned; submissions keep theirs)
        Schema::create('form_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('form_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('draft'); // draft, published, retired
            $table->jsonb('schema');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['form_template_id', 'version']);
        });

        // --- Workflow definitions and immutable versions (WF-001, WF-002) ---
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 64)->default('general');
            $table->string('numbering_pattern', 100)->default('{KEY}/{YYYY}/{SEQ:5}');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('draft'); // draft, published, retired
            $table->foreignId('form_version_id')->nullable()->constrained()->nullOnDelete();
            // initiators, fallback assignee, title template, resubmission policy ...
            $table->jsonb('settings');
            $table->char('checksum', 64)->nullable(); // of the graph at publication
            $table->text('change_note')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workflow_definition_id', 'version']);
        });

        Schema::create('workflow_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_version_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('type', 32);
            $table->string('name');
            $table->jsonb('config');
            $table->integer('position_x')->default(0);
            $table->integer('position_y')->default(0);

            $table->unique(['workflow_version_id', 'key']);
        });

        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_version_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('from_node', 64);
            $table->string('to_node', 64);
            $table->string('outcome', 32)->default('default'); // approve, reject, default, success, failure
            $table->jsonb('condition')->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->string('label')->nullable();

            $table->unique(['workflow_version_id', 'key']);
            $table->index(['workflow_version_id', 'from_node']);
        });

        // --- Runtime --------------------------------------------------------
        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->nullable()->unique(); // assigned on submission
            $table->foreignId('workflow_definition_id')->constrained();
            $table->foreignId('workflow_version_id')->constrained();
            $table->foreignId('form_version_id')->nullable()->constrained();
            $table->foreignId('initiator_id')->constrained('users');
            $table->foreignId('department_id')->nullable()->constrained();
            $table->string('title');
            // draft, in_progress, returned, on_hold, completed, rejected, cancelled
            $table->string('status', 16)->default('draft');
            $table->string('outcome', 16)->nullable(); // approved, rejected, cancelled
            $table->jsonb('data');                    // latest submitted form data
            $table->jsonb('variables');               // engine/automation variables
            $table->unsignedInteger('revision')->default(0);
            $table->string('return_to_node', 64)->nullable();
            $table->nullableMorphs('subject');        // optional domain record (IT request, PO ...)
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('on_hold_since')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['status', 'workflow_definition_id']);
            $table->index(['initiator_id', 'status']);
            $table->index(['department_id', 'status']);
        });

        Schema::create('workflow_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_instance_id')->constrained()->cascadeOnDelete();
            $table->string('node_key', 64);
            $table->string('status', 16)->default('active'); // active, waiting, joined, consumed, cancelled
            $table->foreignId('parent_token_id')->nullable()->constrained('workflow_tokens')->nullOnDelete();
            $table->timestamp('wake_at')->nullable(); // timer nodes
            // Tasks with a larger id belong to the current visit of node_key
            // (a token can revisit a node through a loop).
            $table->unsignedBigInteger('task_floor')->default(0);
            $table->timestamps();

            $table->index(['workflow_instance_id', 'status']);
            $table->index(['status', 'wake_at']);
        });

        Schema::create('workflow_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_token_id')->constrained()->cascadeOnDelete();
            $table->string('node_key', 64);
            $table->string('name');
            $table->string('kind', 32); // approval, review, verification, committee_vote, signature, manual
            $table->foreignId('assignee_id')->constrained('users');
            // Set when the task was routed to a delegate: the person it was meant for.
            $table->foreignId('principal_id')->nullable()->constrained('users');
            $table->jsonb('assigned_via')->nullable();
            $table->foreignId('committee_id')->nullable()->constrained();
            $table->decimal('voting_weight', 6, 2)->nullable();
            // pending, completed, superseded, cancelled
            $table->string('status', 16)->default('pending');
            $table->string('outcome', 32)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->unsignedSmallInteger('escalation_level')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['assignee_id', 'status']);
            $table->index(['workflow_instance_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        // Append-only decision and event history of an instance (WF-018).
        Schema::create('workflow_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('node_key', 64)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->foreignId('on_behalf_of_id')->nullable()->constrained('users');
            $table->string('action', 32);
            $table->boolean('is_decision')->default(false);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->text('remarks')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(['workflow_instance_id', 'id']);
        });

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_version_id')->constrained();
            $table->unsignedInteger('revision');
            $table->jsonb('data');
            $table->foreignId('submitted_by')->constrained('users');
            $table->timestamp('submitted_at');

            $table->unique(['workflow_instance_id', 'revision']);
        });

        // SLA reminders/escalations: one row per (task, kind, level) makes the
        // scan idempotent (WF-015).
        Schema::create('workflow_task_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_task_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // reminder, escalation
            $table->unsignedSmallInteger('level');
            $table->jsonb('recipients')->nullable();
            $table->timestamp('fired_at');

            $table->unique(['workflow_task_id', 'kind', 'level']);
        });

        Schema::create('integration_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workflow_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workflow_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('node_key', 64)->nullable();
            $table->string('action', 100);
            $table->string('idempotency_key', 191)->unique();
            // pending, running, succeeded, failed, blocked
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->jsonb('input')->nullable();
            $table->jsonb('result')->nullable();
            $table->text('last_error')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'action']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // At most one decision per task, whatever the application does (WF-012).
            DB::statement('CREATE UNIQUE INDEX workflow_actions_one_decision_per_task ON workflow_actions (workflow_task_id) WHERE is_decision');
        }
    }

    public function down(): void
    {
        foreach (['integration_jobs', 'workflow_task_events', 'form_submissions', 'workflow_actions', 'workflow_tasks', 'workflow_tokens', 'workflow_instances',
            'workflow_transitions', 'workflow_nodes', 'workflow_versions', 'workflow_definitions', 'form_versions', 'form_templates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
