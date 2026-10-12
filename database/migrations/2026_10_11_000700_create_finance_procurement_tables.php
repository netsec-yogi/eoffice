<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- Budget (FIN-002) ------------------------------------------------
        Schema::create('budget_heads', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32);
            $table->string('name');
            $table->string('financial_year', 9);                 // 2026-27
            $table->foreignId('department_id')->nullable()->constrained();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['code', 'financial_year']);
        });

        // Available = sum(amount) over allocation, reappropriation, commitment
        // (negative), commitment_release (positive), expenditure (negative).
        Schema::create('budget_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_head_id')->constrained();
            $table->string('kind', 24);
            $table->decimal('amount', 15, 2);                     // signed
            $table->nullableMorphs('reference');                 // procurement_case, purchase_order, vendor_invoice, advance
            $table->string('description');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at');

            $table->index(['budget_head_id', 'kind']);
        });

        // ---- Procurement rules: versioned, immutable once published (PROC-002)
        Schema::create('procurement_rule_sets', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->string('status', 16)->default('draft');      // draft, published, retired
            $table->string('title');
            $table->text('authority')->nullable();               // GFR rule / DoE O.M. / institute order relied upon
            $table->jsonb('bands');                              // [{up_to, method, label, quotations, committee}]
            $table->date('effective_from')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // ---- Vendors (VEND-001) ----------------------------------------------
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('gstin', 15)->nullable()->unique();
            $table->string('pan', 10)->nullable();
            $table->text('address')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->text('bank_account')->nullable();             // encrypted at rest (model cast)
            $table->string('ifsc', 11)->nullable();
            $table->boolean('msme')->default(false);
            $table->foreignId('user_id')->nullable()->unique()->constrained();  // vendor portal account
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ---- Procurement cases (PROC-001) -------------------------------------
        Schema::create('procurement_cases', function (Blueprint $table) {
            $table->id();
            $table->string('number', 64)->unique();
            $table->foreignId('workflow_instance_id')->unique()->constrained();  // the indent
            $table->foreignId('department_id')->constrained();
            $table->foreignId('requested_by')->constrained('users');
            $table->string('title', 500);
            $table->jsonb('items');                              // [{description, specification, qty, unit, est_unit_price, amount}]
            $table->decimal('estimated_value', 15, 2);
            $table->foreignId('budget_head_id')->constrained();
            $table->decimal('committed_amount', 15, 2)->default(0);
            $table->foreignId('procurement_rule_set_id')->constrained();
            $table->jsonb('decision');                           // rule version, band, inputs used
            $table->string('method', 32);
            $table->unsignedSmallInteger('quotations_required')->default(0);
            $table->boolean('committee_required')->default(false);
            $table->string('gem_status', 16)->nullable();        // available, not_available, not_applicable
            $table->string('gem_reference')->nullable();
            $table->string('tender_reference')->nullable();
            $table->foreignId('selected_quotation_id')->nullable();
            $table->string('status', 24)->default('indent');     // indent, budgeted, sourcing, evaluated, approved, ordered, received, closed, rejected, cancelled
            $table->timestamps();

            $table->index(['department_id', 'status']);
        });

        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procurement_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained();
            $table->decimal('amount', 15, 2);                     // total quoted, incl. taxes
            $table->boolean('technically_qualified')->default(true);
            $table->text('remarks')->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();

            $table->unique(['procurement_case_id', 'vendor_id']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 64)->unique();
            $table->foreignId('procurement_case_id')->constrained();
            $table->foreignId('vendor_id')->constrained();
            $table->jsonb('items');
            $table->decimal('total', 15, 2);
            $table->date('deliver_by')->nullable();
            $table->text('terms')->nullable();
            $table->string('status', 24)->default('issued');     // issued, partly_received, received, invoiced, closed, cancelled
            $table->timestamp('issued_at');
            $table->foreignId('issued_by')->nullable()->constrained('users');
            $table->foreignId('document_id')->nullable()->constrained('documents');
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 64)->unique();
            $table->foreignId('purchase_order_id')->constrained();
            $table->foreignId('workflow_instance_id')->unique()->constrained();
            $table->date('received_on');
            $table->jsonb('items');                              // [{description, ordered, received, accepted}]
            $table->decimal('accepted_value', 15, 2)->default(0);
            $table->text('inspection_remarks')->nullable();
            $table->string('status', 16)->default('pending');    // pending, accepted, rejected
            $table->timestamps();
        });

        Schema::create('vendor_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 64)->unique();               // internal bill register number
            $table->foreignId('workflow_instance_id')->unique()->constrained();
            $table->foreignId('purchase_order_id')->constrained();
            $table->foreignId('vendor_id')->constrained();
            $table->string('invoice_number', 100);
            $table->date('invoice_date');
            $table->decimal('amount', 15, 2);
            $table->decimal('deductions', 15, 2)->default(0);     // TDS, penalties ... entered at verification
            $table->decimal('net_payable', 15, 2)->nullable();
            $table->string('status', 16)->default('submitted');   // submitted, clarification, verified, sanctioned, paid, rejected
            $table->text('clarification')->nullable();            // the only internal text a vendor sees
            $table->string('payment_reference')->nullable();
            $table->date('paid_on')->nullable();
            $table->foreignId('payment_advice_id')->nullable()->constrained('documents');
            $table->timestamps();

            $table->unique(['vendor_id', 'invoice_number']);
            $table->index(['vendor_id', 'status']);
        });

        // ---- Advances (FIN-001) ---------------------------------------------
        Schema::create('advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('workflow_instance_id')->unique()->constrained();
            $table->foreignId('budget_head_id')->nullable()->constrained();
            $table->string('purpose', 500);
            $table->decimal('amount_requested', 15, 2);
            $table->decimal('amount_sanctioned', 15, 2)->nullable();
            $table->date('settle_by')->nullable();
            $table->string('status', 16)->default('requested');  // requested, sanctioned, disbursed, settled, rejected, cancelled
            $table->string('disbursement_reference')->nullable();
            $table->foreignId('settlement_instance_id')->nullable()->constrained('workflow_instances');
            $table->decimal('spent', 15, 2)->nullable();
            $table->decimal('balance', 15, 2)->nullable();       // + refundable by employee, - payable to employee
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // One live invoice per vendor invoice number is above; one PO per case:
            DB::statement("CREATE UNIQUE INDEX purchase_orders_one_live_per_case ON purchase_orders (procurement_case_id) WHERE status <> 'cancelled'");
        }
    }

    public function down(): void
    {
        foreach (['advances', 'vendor_invoices', 'goods_receipts', 'purchase_orders', 'quotations', 'procurement_cases', 'vendors', 'procurement_rule_sets', 'budget_entries', 'budget_heads'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
