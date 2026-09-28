<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('prepared');
            $table->json('snapshot');
            $table->foreignId('prepared_by')->constrained('users');
            $table->foreignId('filed_by')->nullable()->constrained('users');
            $table->date('filed_on')->nullable();
            $table->string('fta_reference')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'starts_on', 'ends_on'], 'vat_returns_org_period_unique');
        });
        Schema::create('vat_return_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vat_return_id')->constrained()->cascadeOnDelete();
            $table->date('discovered_on');
            $table->decimal('output_vat_delta', 16, 2)->default(0);
            $table->decimal('input_vat_delta', 16, 2)->default(0);
            $table->string('correction_method');
            $table->text('reason');
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_return_adjustments');
        Schema::dropIfExists('vat_returns');
    }
};
