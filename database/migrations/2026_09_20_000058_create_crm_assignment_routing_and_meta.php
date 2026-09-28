<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('timezone', 64)->default('UTC');
        });
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->string('project_name')->nullable();
            $table->string('campaign_name')->nullable();
            $table->string('meta_form_id', 100)->nullable();
            $table->string('meta_form_name')->nullable();
            $table->string('meta_lead_id', 100)->nullable()->unique();
        });
        Schema::create('crm_assignment_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('match_type', 30);
            $table->string('match_value');
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('member_ids')->nullable();
            $table->foreignId('last_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'match_type', 'match_value'], 'crm_assignment_route_match_unique');
        });
        Schema::create('crm_assignment_agents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('max_active_leads')->default(0);
            $table->date('available_on')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });
        Schema::create('crm_assignment_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->unique()->constrained('crm_leads')->cascadeOnDelete();
            $table->unsignedInteger('episode')->default(1);
            $table->string('reason', 50);
            $table->string('route_label')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'resolved_at', 'created_at'], 'crm_assignment_hold_queue_idx');
        });
        Schema::create('crm_meta_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('page_id', 100)->unique();
            $table->text('page_access_token');
            $table->boolean('active')->default(true);
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('crm_meta_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meta_page_id')->constrained('crm_meta_pages')->cascadeOnDelete();
            $table->string('leadgen_id', 100)->unique();
            $table->string('form_id', 100)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('error', 500)->nullable();
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_meta_imports');
        Schema::dropIfExists('crm_meta_pages');
        Schema::dropIfExists('crm_assignment_holds');
        Schema::dropIfExists('crm_assignment_agents');
        Schema::dropIfExists('crm_assignment_routes');
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropUnique(['meta_lead_id']);
            $table->dropColumn(['project_name', 'campaign_name', 'meta_form_id', 'meta_form_name', 'meta_lead_id']);
        });
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
