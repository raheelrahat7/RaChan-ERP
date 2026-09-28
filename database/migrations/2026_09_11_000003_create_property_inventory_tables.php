<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->string('address_line_1')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('buildings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('floors')->nullable();
            $table->timestamps();
            $table->unique(['property_id', 'name']);
        });

        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number');
            $table->string('type');
            $table->decimal('area', 12, 2)->nullable();
            $table->string('area_unit', 10)->default('sq_ft');
            $table->string('status')->default('available');
            $table->decimal('asking_price', 16, 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->timestamps();
            $table->unique(['property_id', 'number']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('properties');
    }
};
