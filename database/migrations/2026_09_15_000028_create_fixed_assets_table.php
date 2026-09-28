<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_bill_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('opening_source_reference', 100)->nullable();
            $table->string('reference', 100);
            $table->string('name');
            $table->string('asset_class');
            $table->string('classification')->default('ias16_cost_model');
            $table->decimal('cost', 16, 2);
            $table->decimal('residual_value', 16, 2)->default(0);
            $table->unsignedInteger('useful_life_months');
            $table->date('available_for_use_on');
            $table->date('disposed_on')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'reference'], 'fixed_assets_org_reference_unique');
            $table->index(['organization_id', 'available_for_use_on'], 'fixed_assets_org_available_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
