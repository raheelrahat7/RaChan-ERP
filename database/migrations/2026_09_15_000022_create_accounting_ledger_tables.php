<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('type', 16);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code'], 'ledger_org_code_unique');
        });

        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('open');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'starts_on', 'ends_on'], 'period_org_dates_idx');
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->foreignId('accounting_period_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->unique()->after('accounting_period_id')->constrained('journal_entries')->restrictOnDelete();
        });

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->string('description')->nullable();
            $table->decimal('debit', 16, 2)->default(0);
            $table->decimal('credit', 16, 2)->default(0);
            $table->timestamps();
            $table->index(['ledger_account_id', 'journal_entry_id'], 'journal_lines_account_entry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('accounting_period_id');
            $table->dropConstrainedForeignId('reversal_of_id');
        });
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('ledger_accounts');
    }
};
