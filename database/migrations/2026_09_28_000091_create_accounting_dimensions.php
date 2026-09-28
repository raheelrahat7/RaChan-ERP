<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_companies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });
        Schema::create('accounting_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('accounting_companies')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['organization_id', 'company_id']);
        });
        Schema::create('accounting_cost_centres', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('accounting_branches')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
            $table->index(['organization_id', 'branch_id']);
        });
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->constrained('accounting_companies')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('accounting_branches')->restrictOnDelete();
            $table->foreignId('cost_centre_id')->nullable()->constrained('accounting_cost_centres')->restrictOnDelete();
            $table->index(['company_id', 'branch_id', 'cost_centre_id'], 'journal_dimension_idx');
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cost_centre_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('company_id');
        });
        Schema::dropIfExists('accounting_cost_centres');
        Schema::dropIfExists('accounting_branches');
        Schema::dropIfExists('accounting_companies');
    }
};
