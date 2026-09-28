<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operating_budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('draft');
            $table->string('currency', 3)->default('AED');
            $table->date('effective_from')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('operating_budgets')->restrictOnDelete();
            $table->text('reason');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'year', 'version'], 'operating_budget_org_year_version');
            $table->index(['organization_id', 'year', 'status'], 'operating_budget_org_year_status');
            $table->index(['organization_id', 'year', 'effective_from'], 'operating_budget_org_year_effective');
        });

        Schema::create('operating_budget_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operating_budget_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->unsignedTinyInteger('month');
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->unique(['operating_budget_id', 'ledger_account_id', 'month'], 'operating_budget_line_unique');
            $table->index(['ledger_account_id', 'month'], 'operating_budget_line_account_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operating_budget_lines');
        Schema::dropIfExists('operating_budgets');
    }
};
