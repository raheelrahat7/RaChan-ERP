<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_depreciations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->restrictOnDelete();
            $table->char('month', 7);
            $table->decimal('amount', 16, 2);
            $table->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['fixed_asset_id', 'month'], 'asset_depreciations_asset_month_unique');
            $table->index(['organization_id', 'month'], 'asset_depreciations_org_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciations');
    }
};
