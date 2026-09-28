<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preventive_maintenance_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('maintenance_vendors')->nullOnDelete();
            $table->string('title');
            $table->unsignedInteger('frequency_days');
            $table->date('next_due_on');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['organization_id', 'is_active', 'next_due_on'], 'pmp_org_active_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preventive_maintenance_plans');
    }
};
