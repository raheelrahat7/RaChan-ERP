<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_match_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('viewing_statuses');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('crm_lead_listing_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->unsignedTinyInteger('match_percent_override')->nullable();
            $table->boolean('shared')->default(false);
            $table->string('viewing_status', 20)->default('not_scheduled');
            $table->dateTime('viewing_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['lead_id', 'listing_id']);
            $table->index(['organization_id', 'lead_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_listing_matches');
        Schema::dropIfExists('crm_lead_match_settings');
    }
};
