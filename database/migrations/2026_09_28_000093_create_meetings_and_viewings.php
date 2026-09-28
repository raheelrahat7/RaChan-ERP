<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brokerage_appointments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->restrictOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained('listings')->restrictOnDelete();
            $table->foreignId('cost_centre_id')->nullable()->constrained('accounting_cost_centres')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('title');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('location')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->text('outcome')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'starts_at']);
            $table->index(['organization_id', 'assigned_to', 'starts_at'], 'appointments_org_assignee_start_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brokerage_appointments');
    }
};
