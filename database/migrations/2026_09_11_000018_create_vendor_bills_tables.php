<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_bills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('maintenance_vendors')->restrictOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('description');
            $table->string('status')->default('draft');
            $table->date('bill_date');
            $table->date('due_on')->nullable();
            $table->decimal('total', 16, 2);
            $table->char('currency', 3)->default('AED');
            $table->timestamps();
            $table->index(['organization_id', 'status', 'due_on'], 'vendor_bills_org_status_due_idx');
        });
        Schema::create('vendor_bill_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_bill_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->decimal('amount', 16, 2);
            $table->date('paid_on');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_bill_payments');
        Schema::dropIfExists('vendor_bills');
    }
};
