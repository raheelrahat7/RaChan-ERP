<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('status')->default('draft');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('rent_amount', 16, 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
        Schema::create('sales_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('status')->default('draft');
            $table->date('contracted_on');
            $table->decimal('sale_price', 16, 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_contracts');
        Schema::dropIfExists('leases');
    }
};
