<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vat_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained();
            $table->string('type');
            $table->decimal('output_vat', 16, 2);
            $table->decimal('input_vat', 16, 2);
            $table->decimal('net_vat', 16, 2);
            $table->foreignId('journal_entry_id')->constrained();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries');
            $table->foreignId('approved_by')->constrained('users');
            $table->foreignId('reversed_by')->nullable()->constrained('users');
            $table->foreignId('receipt_journal_entry_id')->nullable()->constrained('journal_entries');
            $table->foreignId('receipt_reversal_journal_entry_id')->nullable()->constrained('journal_entries');
            $table->timestamps();
            $table->index(['vat_return_id', 'reversal_journal_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_settlements');
    }
};
