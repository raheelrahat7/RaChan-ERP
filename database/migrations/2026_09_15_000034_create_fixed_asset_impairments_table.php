<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_impairments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('fixed_asset_review_id')->constrained()->restrictOnDelete();
            $table->date('posted_on');
            $table->char('effective_month', 7);
            $table->decimal('amount', 16, 2);
            $table->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['fixed_asset_id', 'effective_month', 'reversal_journal_entry_id'], 'asset_impairments_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_impairments');
    }
};
