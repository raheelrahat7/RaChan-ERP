<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_vehicles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('fixed_asset_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference', 100);
            $table->string('plate', 100);
            $table->string('vin', 100)->nullable();
            $table->string('make');
            $table->string('model');
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedBigInteger('odometer')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['organization_id', 'reference']);
            $table->unique(['organization_id', 'plate']);
            $table->unique(['organization_id', 'vin']);
        });
        Schema::create('fleet_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('fleet_vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('fleet_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('fleet_vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('maintenance_vendors')->restrictOnDelete();
            $table->foreignId('maintenance_request_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->uuid('operation_key');
            $table->string('kind', 20);
            $table->string('description');
            $table->date('due_on');
            $table->string('status', 20)->default('planned');
            $table->unsignedBigInteger('completed_odometer')->nullable();
            $table->text('completion_note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'operation_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_services');
        Schema::dropIfExists('fleet_assignments');
        Schema::dropIfExists('fleet_vehicles');
    }
};
