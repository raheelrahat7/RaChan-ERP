<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_asset_depreciations', function (Blueprint $table): void {
            $table->index('fixed_asset_id', 'asset_depreciations_asset_idx');
        });

        Schema::table('fixed_asset_depreciations', function (Blueprint $table): void {
            $table->dropUnique('asset_depreciations_asset_month_unique');
            $table->foreignId('reversal_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['fixed_asset_id', 'month', 'reversal_journal_entry_id'], 'asset_depreciations_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_asset_depreciations', function (Blueprint $table): void {
            $table->dropIndex('asset_depreciations_active_idx');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropConstrainedForeignId('reversal_journal_entry_id');
            $table->unique(['fixed_asset_id', 'month'], 'asset_depreciations_asset_month_unique');
        });

        Schema::table('fixed_asset_depreciations', function (Blueprint $table): void {
            $table->dropIndex('asset_depreciations_asset_idx');
        });
    }
};
