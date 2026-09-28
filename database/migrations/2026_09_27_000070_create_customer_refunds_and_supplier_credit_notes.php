<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_credit_note_id')->constrained()->restrictOnDelete();
            $table->string('reference', 32)->unique();
            $table->decimal('amount', 14, 2);
            $table->text('reason');
            $table->string('status', 24)->default('submitted');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->date('posted_on')->nullable();
            $table->date('reversed_on')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 2000)->nullable();
            $table->string('reversal_reason', 2000)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'invoice_id', 'status'], 'customer_refund_invoice_status_idx');
        });

        Schema::create('vendor_credit_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_bill_id')->constrained()->restrictOnDelete();
            $table->string('reference', 32)->unique();
            $table->decimal('amount', 14, 2);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->string('vat_treatment', 24)->nullable();
            $table->boolean('input_vat_recoverable')->default(false);
            $table->string('accounting_treatment', 24);
            $table->text('reason');
            $table->string('status', 24)->default('submitted');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->date('posted_on')->nullable();
            $table->date('reversed_on')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 2000)->nullable();
            $table->string('reversal_reason', 2000)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'vendor_bill_id', 'status'], 'vendor_credit_bill_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_credit_notes');
        Schema::dropIfExists('customer_refunds');
    }
};
