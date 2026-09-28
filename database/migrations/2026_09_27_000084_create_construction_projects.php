<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference', 100);
            $table->string('title');
            $table->unsignedBigInteger('budget_cents');
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'reference']);
        });
        Schema::create('construction_boq_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('construction_project_id')->constrained()->restrictOnDelete();
            $table->string('reference', 100);
            $table->string('description');
            $table->string('unit', 30);
            $table->unsignedBigInteger('quantity');
            $table->unsignedBigInteger('unit_rate_cents');
            $table->unsignedBigInteger('amount_cents');
            $table->timestamps();
            $table->unique(['construction_project_id', 'reference'], 'boq_project_reference_unique');
        });
        Schema::create('construction_boq_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('construction_boq_item_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quantity');
            $table->uuid('operation_key');
            $table->text('note');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'operation_key'], 'boq_progress_operation_unique');
        });
        Schema::create('contractor_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('construction_project_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('maintenance_vendors')->restrictOnDelete();
            $table->string('reference', 32)->unique();
            $table->uuid('operation_key');
            $table->date('claimed_on');
            $table->unsignedBigInteger('amount_cents');
            $table->text('reason');
            $table->string('status', 24)->default('submitted');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('vendor_bill_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'operation_key'], 'contractor_claim_operation_unique');
        });
        Schema::create('contractor_claim_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('contractor_claim_id')->constrained()->restrictOnDelete();
            $table->foreignId('construction_boq_item_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quantity');
            $table->unsignedBigInteger('unit_rate_cents');
            $table->unsignedBigInteger('amount_cents');
            $table->timestamps();
            $table->unique(['contractor_claim_id', 'construction_boq_item_id'], 'claim_boq_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_claim_lines');
        Schema::dropIfExists('contractor_claims');
        Schema::dropIfExists('construction_boq_progress');
        Schema::dropIfExists('construction_boq_items');
        Schema::dropIfExists('construction_projects');
    }
};
