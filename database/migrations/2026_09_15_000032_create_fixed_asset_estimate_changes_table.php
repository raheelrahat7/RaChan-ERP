<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_estimate_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_asset_review_id')->unique()->constrained()->restrictOnDelete();
            $table->char('effective_month', 7);
            $table->decimal('residual_value', 16, 2);
            $table->unsignedInteger('remaining_life_months');
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['fixed_asset_id', 'effective_month'], 'asset_estimates_asset_month_unique');
            $table->index(['organization_id', 'effective_month'], 'asset_estimates_org_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_estimate_changes');
    }
};
