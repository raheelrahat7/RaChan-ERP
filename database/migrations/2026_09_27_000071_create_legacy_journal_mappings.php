<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_mapping_approvers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });
        Schema::create('legacy_journal_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('submitted');
            $table->json('source_snapshot');
            $table->json('lines');
            $table->text('reason');
            $table->string('evidence_reference', 255);
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'journal_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_journal_mappings');
        Schema::dropIfExists('journal_mapping_approvers');
    }
};
