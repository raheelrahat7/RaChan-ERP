<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->foreignId('ledger_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
        });

        Schema::create('bank_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_statement_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['bank_statement_line_id', 'reversal_journal_entry_id'], 'bank_settlement_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_settlements');
        Schema::table('bank_accounts', fn (Blueprint $table) => $table->dropConstrainedForeignId('ledger_account_id'));
    }
};
