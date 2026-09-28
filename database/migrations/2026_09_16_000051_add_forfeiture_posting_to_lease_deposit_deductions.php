<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_deposit_deductions', function (Blueprint $table): void {
            $table->foreignId('forfeiture_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->date('forfeiture_posted_on')->nullable();
            $table->foreignId('forfeiture_approved_by')->nullable()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('lease_deposit_deductions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('forfeiture_journal_entry_id');
            $table->dropColumn(['forfeiture_posted_on', 'forfeiture_approved_by']);
        });
    }
};
