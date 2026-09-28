<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('review_year');
            $table->date('reviewed_on');
            $table->string('outcome', 32);
            $table->decimal('residual_value_snapshot', 16, 2);
            $table->unsignedInteger('useful_life_months_snapshot');
            $table->string('depreciation_method')->default('straight_line');
            $table->boolean('impairment_assessment_required')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['fixed_asset_id', 'review_year'], 'asset_reviews_asset_year_unique');
            $table->index(['organization_id', 'review_year'], 'asset_reviews_org_year_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_reviews');
    }
};
