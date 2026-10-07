<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_requirement_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('choices');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('crm_lead_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->unique()->constrained('crm_leads')->cascadeOnDelete();
            $table->json('data');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_requirements');
        Schema::dropIfExists('crm_lead_requirement_settings');
    }
};
