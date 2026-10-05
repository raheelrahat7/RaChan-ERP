<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_currencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 3);
            $table->string('name', 100);
            $table->decimal('exchange_rate', 24, 10)->default(1);
            $table->unsignedInteger('face_value')->default(1);
            $table->boolean('is_base')->default(false);
            $table->boolean('is_reporting')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(100);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });
        Schema::create('organization_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('organization_locations')->restrictOnDelete();
            $table->string('type', 16);
            $table->string('name', 120);
            $table->json('translations')->nullable();
            $table->unsignedInteger('position')->default(100);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('organization_numbering_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('prefix', 40);
            $table->unsignedTinyInteger('padding')->default(6);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->boolean('include_year')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'kind']);
        });
        Schema::create('organization_allocated_numbers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('reference', 100);
            $table->uuid('operation_key');
            $table->timestamps();
            $table->unique(['organization_id', 'kind', 'reference'], 'allocated_numbers_reference_unique');
            $table->unique(['organization_id', 'kind', 'operation_key'], 'allocated_numbers_operation_unique');
        });
        Schema::create('organization_provider_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('capability', 24);
            $table->string('name', 100);
            $table->string('provider', 100)->nullable();
            $table->boolean('active')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
        Schema::create('organization_provider_packets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained('organization_provider_profiles')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('operation_key');
            $table->string('sha256', 64);
            $table->json('payload');
            $table->timestamps();
            $table->unique(['organization_id', 'operation_key'], 'provider_packets_operation_unique');
        });
    }

    public function down(): void
    {
        foreach (['organization_provider_packets', 'organization_provider_profiles', 'organization_allocated_numbers', 'organization_numbering_templates', 'organization_locations', 'organization_currencies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
