<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_service_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->string('category');
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->decimal('net_amount', 16, 2);
            $table->string('vat_treatment')->nullable();
            $table->date('due_on');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['organization_id', 'lease_id', 'period_starts_on'], 'lease_service_charges_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_service_charges');
    }
};
