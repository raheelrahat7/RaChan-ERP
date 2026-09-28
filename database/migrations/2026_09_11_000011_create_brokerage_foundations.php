<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_owner', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained()->cascadeOnDelete();
            $table->decimal('ownership_share', 5, 2)->default(100);
            $table->timestamps();
            $table->unique(['property_id', 'owner_id']);
        });
        Schema::create('listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('purpose');
            $table->string('status')->default('draft');
            $table->decimal('price', 16, 2);
            $table->char('currency', 3)->default('AED');
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
        Schema::create('commission_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('basis')->default('percentage');
            $table->decimal('rate', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_plans');
        Schema::dropIfExists('listings');
        Schema::dropIfExists('property_owner');
    }
};
