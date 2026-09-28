<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_search_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->unique()->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 10);
            $table->string('city')->nullable();
            $table->string('property_type', 40)->nullable();
            $table->decimal('min_price_aed', 16, 2)->nullable();
            $table->decimal('max_price_aed', 16, 2)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'purpose'], 'lead_preference_purpose_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_search_preferences');
    }
};
