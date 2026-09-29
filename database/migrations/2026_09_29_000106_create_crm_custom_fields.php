<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_custom_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('key', 80);
            $table->string('type', 24);
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('active')->default(true);
            $table->json('view_roles')->nullable();
            $table->json('edit_roles')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
            $table->index(['organization_id', 'active', 'sort_order']);
        });

        Schema::create('crm_custom_field_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('field_id')->constrained('crm_custom_fields')->restrictOnDelete();
            $table->json('value')->nullable();
            $table->string('search_text', 500)->nullable();
            $table->decimal('number_value', 18, 4)->nullable();
            $table->dateTime('date_value')->nullable();
            $table->unsignedBigInteger('user_value')->nullable();
            $table->timestamps();
            $table->unique(['lead_id', 'field_id']);
            $table->index(['organization_id', 'field_id', 'number_value'], 'crm_value_number_idx');
            $table->index(['organization_id', 'field_id', 'date_value'], 'crm_value_date_idx');
            $table->index(['organization_id', 'field_id', 'user_value'], 'crm_value_user_idx');
            $table->index(['organization_id', 'field_id', 'search_text'], 'crm_value_text_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_custom_field_values');
        Schema::dropIfExists('crm_custom_fields');
    }
};
