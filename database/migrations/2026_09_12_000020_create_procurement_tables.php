<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('purpose');
            $table->string('status')->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status'], 'pr_org_status_idx');
        });
        Schema::create('purchase_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 2);
            $table->string('unit', 32)->default('each');
            $table->timestamps();
        });
        Schema::create('procurement_rfqs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('maintenance_vendors')->restrictOnDelete();
            $table->string('reference')->unique();
            $table->date('due_on')->nullable();
            $table->string('status')->default('sent');
            $table->timestamps();
            $table->unique(['purchase_request_id', 'vendor_id'], 'rfq_request_vendor_unique');
        });
        Schema::create('procurement_quotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_id')->unique()->constrained('procurement_rfqs')->restrictOnDelete();
            $table->string('vendor_reference')->nullable();
            $table->decimal('total', 16, 2);
            $table->char('currency', 3)->default('AED');
            $table->string('status')->default('received');
            $table->timestamps();
        });
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('quotation_id')->unique()->constrained('procurement_quotations')->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('maintenance_vendors')->restrictOnDelete();
            $table->foreignId('vendor_bill_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->decimal('total', 16, 2);
            $table->char('currency', 3)->default('AED');
            $table->string('status')->default('issued');
            $table->date('received_on')->nullable();
            $table->text('receipt_note')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status'], 'po_org_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('procurement_quotations');
        Schema::dropIfExists('procurement_rfqs');
        Schema::dropIfExists('purchase_request_lines');
        Schema::dropIfExists('purchase_requests');
    }
};
