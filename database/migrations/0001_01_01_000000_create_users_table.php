<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Organisational units form a tree: institute > school > department,
        // or administration > office (Registrar, Finance, Purchase ...).
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('type', 32)->default('academic'); // academic, administrative, centre, office
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            // Seniority hint for reports; never used to infer approval authority.
            $table->unsignedSmallInteger('level')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // Directory login name (LDAP uid) or, for local accounts, the
            // name chosen at creation. Case-folded on write.
            $table->string('username', 100)->unique();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone', 32)->nullable();
            $table->string('employee_id', 64)->nullable()->unique(); // employee or enrolment number
            $table->string('category', 32)->default('staff'); // faculty, staff, student, external, vendor
            $table->string('auth_source', 16)->default('ldap'); // ldap | local
            $table->string('ldap_dn')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reports_to_id')->nullable()->constrained('users')->nullOnDelete();
            // Only local accounts have a password. LDAP passwords are never stored.
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);           // local switch, set by administrators
            $table->boolean('directory_disabled')->default(false); // mirrored from the directory
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['department_id', 'category']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('designations');
        Schema::dropIfExists('departments');
    }
};
