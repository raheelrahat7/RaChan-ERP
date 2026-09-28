<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_cash_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_bill_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_credit_note_id')->constrained()->restrictOnDelete();
            $table->string('reference', 32)->unique();
            $table->uuid('operation_key');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('AED');
            $table->text('reason');
            $table->string('status', 24)->default('submitted');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->date('posted_on');
            $table->date('reversed_on')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'operation_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_cash_refunds');
    }
};
