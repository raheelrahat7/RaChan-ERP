<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations_equipment', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('reference');
            $table->string('name');
            $table->string('serial_number')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'reference']);
        });
        Schema::create('amc_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('maintenance_vendors')->restrictOnDelete();
            $table->string('reference');
            $table->string('title');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('service_limit')->nullable();
            $table->text('terms')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'reference']);
        });
        Schema::create('amc_contract_properties', function (Blueprint $table): void {
            $table->foreignId('amc_contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->primary(['amc_contract_id', 'property_id']);
        });
        Schema::create('amc_contract_equipment', function (Blueprint $table): void {
            $table->foreignId('amc_contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('operations_equipment_id')->constrained('operations_equipment')->restrictOnDelete();
            $table->primary(['amc_contract_id', 'operations_equipment_id']);
        });
        Schema::create('amc_service_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('amc_contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('maintenance_request_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('operations_equipment_id')->nullable()->constrained('operations_equipment')->restrictOnDelete();
            $table->date('service_on');
            $table->text('override_reason')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amc_service_visits');
        Schema::dropIfExists('amc_contract_equipment');
        Schema::dropIfExists('amc_contract_properties');
        Schema::dropIfExists('amc_contracts');
        Schema::dropIfExists('operations_equipment');
    }
};
