<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_deposit_deductions', function (Blueprint $table): void {
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('offset_payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignId('offset_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->date('offset_posted_on')->nullable();
            $table->foreignId('offset_approved_by')->nullable()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('lease_deposit_deductions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropConstrainedForeignId('offset_payment_id');
            $table->dropConstrainedForeignId('offset_journal_entry_id');
            $table->dropColumn(['offset_posted_on', 'offset_approved_by']);
        });
    }
};
