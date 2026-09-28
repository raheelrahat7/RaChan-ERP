<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_deposit_deductions', function (Blueprint $table): void {
            $table->string('vat_treatment')->nullable();
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('vat_amount', 16, 2)->nullable();
            $table->foreignId('recovery_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->date('recovery_posted_on')->nullable();
            $table->foreignId('recovery_approved_by')->nullable()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('lease_deposit_deductions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recovery_journal_entry_id');
            $table->dropColumn(['vat_treatment', 'vat_rate', 'vat_amount', 'recovery_posted_on', 'recovery_approved_by']);
        });
    }
};
