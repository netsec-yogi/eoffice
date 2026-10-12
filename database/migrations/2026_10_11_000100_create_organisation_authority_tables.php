<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Named offices that workflows route to (HOD, DEAN_ACADEMIC,
        // REGISTRAR ...). Workflows reference these, never employee IDs.
        Schema::create('authorities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('scope', 16)->default('institute'); // institute | department
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('authority_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('authority_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Required when the authority is department-scoped.
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_acting')->default(false);
            $table->string('order_reference')->nullable(); // office order that made the appointment
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['authority_id', 'department_id', 'starts_on']);
        });

        Schema::create('committees', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->text('purpose')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('committee_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('committee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('member'); // chair, member, secretary, convener, special_invitee
            $table->decimal('voting_weight', 6, 2)->default(1);
            $table->boolean('is_voting')->default(true);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->index(['committee_id', 'starts_on']);
        });

        Schema::create('delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            // null = every category / department / action the delegator holds.
            $table->jsonb('workflow_categories')->nullable();
            $table->jsonb('department_ids')->nullable();
            $table->jsonb('allowed_actions')->nullable();
            $table->text('reason');
            $table->string('status', 16)->default('active'); // pending_approval, active, revoked
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['delegator_id', 'status', 'starts_at', 'ends_at']);
            $table->index(['delegate_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
        Schema::dropIfExists('committee_members');
        Schema::dropIfExists('committees');
        Schema::dropIfExists('authority_assignments');
        Schema::dropIfExists('authorities');
    }
};
