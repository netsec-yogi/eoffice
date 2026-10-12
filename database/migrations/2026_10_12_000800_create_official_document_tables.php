<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Official PDF forms (DOC-001..006): the institute's own PDF files as
 * versioned templates, how each is filled, which workflows produce it, and
 * every document generated from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('department_id')->nullable()->constrained();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        // The mapping lives in the version: a published version, its file and
        // its mapping never change; a correction is a new version.
        Schema::create('pdf_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdf_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('draft');          // draft, published, superseded
            $table->foreignId('original_document_id')->constrained('documents'); // the file as supplied, untouched
            $table->string('original_filename');
            $table->char('original_sha256', 64);
            $table->string('working_path')->nullable();               // qpdf-normalised copy used for rendering
            $table->unsignedSmallInteger('page_count');
            $table->jsonb('pages');                                   // [{width, height}] in points
            $table->jsonb('form_fields');                             // detected AcroForm widgets [{name, type, page, rect, options}]
            $table->jsonb('mapping');                                 // [{key, page, type, source, x, y, w, h, ...}] in points, top-left origin
            $table->text('change_note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('published_by')->nullable()->constrained('users');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['pdf_template_id', 'version']);
        });

        // Which workflow produces which official document, and on what terms.
        Schema::create('workflow_document_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pdf_template_id')->constrained();
            $table->boolean('is_enabled')->default(true);
            $table->jsonb('required_steps');                          // node keys that must carry an approval
            $table->jsonb('condition')->nullable();                   // rule-language condition on the request data
            $table->jsonb('download_groups');                         // applicant, participants, department, records, administrators
            $table->boolean('confidential')->default(false);
            $table->boolean('requires_signature')->default(false);    // released only after an authorised signature (SIGN-001)
            $table->string('filename_pattern', 191)->default('{template}_{reference}_Approved');
            $table->string('regeneration', 16)->default('authorised'); // authorised, never
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();

            $table->unique(['workflow_definition_id', 'pdf_template_id']);
        });

        Schema::create('official_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workflow_instance_id')->constrained();
            $table->foreignId('workflow_document_output_id')->constrained();
            $table->foreignId('pdf_template_version_id')->nullable()->constrained(); // fixed at submission
            $table->unsignedInteger('document_version')->default(1);
            $table->string('status', 24);                             // waiting_approval, queued, generating, generated, awaiting_signature, failed, not_generated, superseded, archived
            $table->foreignId('document_id')->nullable()->constrained('documents');
            $table->string('filename')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 32)->nullable();             // safe to show
            $table->text('error_detail')->nullable();                 // administrators only
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('official_documents');
            $table->text('regeneration_reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // One current document per request and output: duplicate events cannot fork it.
            DB::statement("CREATE UNIQUE INDEX official_documents_one_current ON official_documents (workflow_instance_id, workflow_document_output_id) WHERE status NOT IN ('superseded', 'archived')");
            // Only one published version per template at a time.
            DB::statement("CREATE UNIQUE INDEX pdf_template_versions_one_published ON pdf_template_versions (pdf_template_id) WHERE status = 'published'");
        }
    }

    public function down(): void
    {
        foreach (['official_documents', 'workflow_document_outputs', 'pdf_template_versions', 'pdf_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
