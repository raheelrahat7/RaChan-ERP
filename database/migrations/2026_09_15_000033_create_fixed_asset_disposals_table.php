<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_disposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('proceeds_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->decimal('proceeds', 16, 2);
            $table->decimal('carrying_amount', 16, 2);
            $table->decimal('gain_loss', 16, 2);
            $table->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['fixed_asset_id', 'reversal_journal_entry_id'], 'asset_disposals_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_disposals');
    }
};
