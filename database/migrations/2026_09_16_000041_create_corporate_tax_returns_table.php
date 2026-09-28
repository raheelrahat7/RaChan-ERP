<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_tax_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('profile');
            $table->decimal('accounting_profit', 16, 2);
            $table->decimal('revenue', 16, 2);
            $table->decimal('exempt_income', 16, 2)->default(0);
            $table->decimal('non_deductible_expenses', 16, 2)->default(0);
            $table->decimal('other_adjustments', 16, 2)->default(0);
            $table->decimal('qualifying_income', 16, 2)->default(0);
            $table->decimal('non_qualifying_income', 16, 2)->default(0);
            $table->decimal('non_qualifying_revenue', 16, 2)->default(0);
            $table->boolean('small_business_relief_elected')->default(false);
            $table->boolean('prior_revenue_threshold_confirmed')->default(false);
            $table->boolean('qfz_conditions_confirmed')->default(false);
            $table->boolean('qfz_de_minimis_met')->nullable();
            $table->decimal('taxable_income', 16, 2);
            $table->decimal('tax_payable', 16, 2);
            $table->text('adjustment_notes');
            $table->foreignId('prepared_by')->constrained('users');
            $table->timestamps();
            $table->unique(['organization_id', 'starts_on', 'ends_on'], 'ct_returns_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_tax_returns');
    }
};
