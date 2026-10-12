<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ledger of access the Computer Centre has granted. Deprovisioning
        // works from this ledger, so nothing is forgotten or revoked twice.
        Schema::create('it_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('kind', 16);                 // email, wifi, vpn, vm
            $table->string('identifier');               // address, group DN, VM id ...
            $table->string('status', 16)->default('active'); // active, suspended, deprovisioned
            $table->jsonb('details')->nullable();       // profile, resources, scope
            $table->string('provisioned_by', 16)->default('automation'); // automation | manual
            $table->foreignId('workflow_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->date('expires_on')->nullable();
            $table->timestamp('provisioned_at');
            $table->timestamp('deprovisioned_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'identifier', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_on']);
        });

        Schema::create('it_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_number', 64)->unique();
            $table->string('category', 64);             // laptop, desktop, printer, network ...
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable()->unique();
            $table->date('purchase_date')->nullable();
            $table->string('purchase_reference')->nullable(); // PO / invoice number
            $table->decimal('cost', 14, 2)->nullable();
            $table->date('warranty_until')->nullable();
            $table->string('location')->nullable();
            $table->foreignId('custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('in_stock'); // in_stock, issued, repair, disposed
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'category']);
        });

        Schema::create('it_asset_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('it_asset_id')->constrained()->cascadeOnDelete();
            $table->string('action', 16);               // register, issue, return, transfer, repair, repaired, dispose
            $table->foreignId('from_user_id')->nullable()->constrained('users');
            $table->foreignId('to_user_id')->nullable()->constrained('users');
            $table->foreignId('by_user_id')->nullable()->constrained('users');
            $table->foreignId('workflow_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamp('created_at');

            $table->index(['it_asset_id', 'id']);
        });

        Schema::create('software_titles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('publisher')->nullable();
            $table->string('licence_type', 32)->default('per_seat'); // per_seat, site, subscription, open_source
            $table->unsignedInteger('seats')->nullable();             // null = unlimited (site licence)
            $table->date('expires_on')->nullable();
            $table->string('agreement_reference')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('licence_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('software_title_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('workflow_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('allocated_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();
            $table->timestamps();

            $table->index(['software_title_id', 'revoked_at']);
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licence_allocations');
        Schema::dropIfExists('software_titles');
        Schema::dropIfExists('it_asset_movements');
        Schema::dropIfExists('it_assets');
        Schema::dropIfExists('it_accounts');
    }
};
