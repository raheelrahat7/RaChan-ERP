<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_deposit_settlements', function (Blueprint $table): void {
            $table->foreignId('refund_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('refund_approved_by')->nullable()->constrained('users');
            $table->date('refund_posted_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lease_deposit_settlements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('refund_journal_entry_id');
            $table->dropConstrainedForeignId('refund_approved_by');
            $table->dropColumn('refund_posted_on');
        });
    }
};
