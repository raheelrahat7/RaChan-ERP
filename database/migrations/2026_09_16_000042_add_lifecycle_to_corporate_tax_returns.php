<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corporate_tax_returns', function (Blueprint $table): void {
            $table->string('status')->default('prepared');
            $table->foreignId('approved_by')->nullable()->constrained('users', indexName: 'ct_returns_approver_fk');
            $table->foreignId('provision_journal_entry_id')->nullable()->constrained('journal_entries', indexName: 'ct_returns_provision_fk');
            $table->foreignId('provision_reversal_journal_entry_id')->nullable()->constrained('journal_entries', indexName: 'ct_returns_provision_reversal_fk');
            $table->date('filed_on')->nullable();
            $table->string('fta_reference')->nullable();
            $table->foreignId('filed_by')->nullable()->constrained('users', indexName: 'ct_returns_filer_fk');
            $table->foreignId('bank_account_id')->nullable()->constrained(indexName: 'ct_returns_bank_fk');
            $table->foreignId('payment_journal_entry_id')->nullable()->constrained('journal_entries', indexName: 'ct_returns_payment_fk');
            $table->foreignId('payment_reversal_journal_entry_id')->nullable()->constrained('journal_entries', indexName: 'ct_returns_payment_reversal_fk');
        });
    }

    public function down(): void
    {
        Schema::table('corporate_tax_returns', fn (Blueprint $table) => $table->dropColumn(['status', 'approved_by', 'provision_journal_entry_id', 'provision_reversal_journal_entry_id', 'filed_on', 'fta_reference', 'filed_by', 'bank_account_id', 'payment_journal_entry_id', 'payment_reversal_journal_entry_id']));
    }
};
