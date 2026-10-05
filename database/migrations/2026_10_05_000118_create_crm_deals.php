<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_deal_pipelines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(100);
            $table->boolean('active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('access_configured')->default(false);
            $table->timestamps();
            $table->index(['organization_id', 'active', 'position']);
        });
        Schema::create('crm_deal_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('crm_deal_pipelines')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 16)->default('normal');
            $table->string('color', 7)->default('#38bdf8');
            $table->unsignedInteger('position');
            $table->boolean('active')->default(true);
            $table->boolean('is_initial')->default(false);
            $table->json('allowed_from_stage_ids')->nullable();
            $table->json('entry_roles')->nullable();
            $table->json('required_fields')->nullable();
            $table->timestamps();
        });
        Schema::create('crm_deal_access_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained('crm_deal_pipelines')->cascadeOnDelete();
            $table->string('principal_type', 20);
            $table->string('principal_id', 40);
            $table->json('permissions');
            $table->timestamps();
            $table->unique(['pipeline_id', 'principal_type', 'principal_id'], 'crm_deal_access_unique');
        });
        Schema::create('crm_deals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained('crm_deal_pipelines')->restrictOnDelete();
            $table->foreignId('current_stage_id')->constrained('crm_deal_stages')->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained('listings')->restrictOnDelete();
            $table->string('title', 255);
            $table->string('category', 24)->default('secondary');
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('company')->nullable();
            $table->string('source', 100)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('currency', 3)->default('AED');
            $table->date('expected_close_date')->nullable();
            $table->string('lost_reason', 255)->nullable();
            $table->timestamp('stage_changed_at');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'lead_id']);
            $table->index(['organization_id', 'pipeline_id', 'current_stage_id']);
            $table->index(['organization_id', 'assigned_to']);
        });
        Schema::create('crm_deal_stage_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->constrained('crm_deals')->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained('crm_deal_pipelines')->restrictOnDelete();
            $table->foreignId('from_stage_id')->nullable()->constrained('crm_deal_stages')->restrictOnDelete();
            $table->foreignId('to_stage_id')->constrained('crm_deal_stages')->restrictOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('snapshot');
            $table->timestamp('changed_at');
            $table->index(['organization_id', 'deal_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_deal_stage_histories');
        Schema::dropIfExists('crm_deals');
        Schema::dropIfExists('crm_deal_access_rules');
        Schema::dropIfExists('crm_deal_stages');
        Schema::dropIfExists('crm_deal_pipelines');
    }
};
