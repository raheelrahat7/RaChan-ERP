<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancy_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('previous_lease_id')->nullable()->constrained('leases')->nullOnDelete();
            $table->foreignId('handover_checklist_id')->nullable()->constrained()->nullOnDelete();
            $table->date('vacant_from');
            $table->date('target_ready_on')->nullable();
            $table->string('readiness_status')->default('inspection');
            $table->text('notes')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->string('resolution')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'resolved_at'], 'vacancy_org_resolved_idx');
            $table->index(['organization_id', 'readiness_status'], 'vacancy_org_readiness_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancy_records');
    }
};
