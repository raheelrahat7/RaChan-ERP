<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_credit_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('reference')->unique();
            $table->string('status')->default('draft');
            $table->decimal('amount', 14, 2);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->string('vat_treatment')->nullable();
            $table->text('reason');
            $table->date('posted_on')->nullable();
            $table->date('reversed_on')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'invoice_id'], 'credit_notes_org_invoice');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_notes');
    }
};
