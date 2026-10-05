<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_section_access', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('principal_type', 20);
            $table->string('principal_id', 40);
            $table->string('permission', 64);
            $table->boolean('enabled');
            $table->timestamps();
            $table->unique(['organization_id', 'principal_type', 'principal_id', 'permission'], 'section_access_unique');
        });
        Schema::create('crm_selection_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('list_key', 40);
            $table->string('name', 120);
            $table->string('code', 24)->nullable();
            $table->unique(['organization_id', 'list_key', 'code']);
            $table->unsignedInteger('position')->default(100);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'list_key', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_selection_options');
        Schema::dropIfExists('organization_section_access');
    }
};
