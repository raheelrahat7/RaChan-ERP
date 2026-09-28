<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->char('currency', 3)->default('AED');
            $table->timestamps();
            $table->unique(['organization_id', 'name'], 'bank_accounts_org_name_unique');
        });

        Schema::create('bank_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->uuid('import_batch');
            $table->string('external_id', 100);
            $table->date('occurred_on');
            $table->string('description');
            $table->decimal('amount', 16, 2);
            $table->foreignId('matched_journal_line_id')->nullable()->unique()->constrained('journal_lines')->restrictOnDelete();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();
            $table->unique(['bank_account_id', 'external_id'], 'bank_lines_account_external_unique');
            $table->index(['organization_id', 'bank_account_id', 'occurred_on'], 'bank_lines_org_account_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_accounts');
    }
};
