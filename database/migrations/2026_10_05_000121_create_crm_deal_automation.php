<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_deal_automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained('crm_deal_pipelines')->restrictOnDelete();
            $table->foreignId('stage_id')->constrained('crm_deal_stages')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('action', 30);
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->unsignedInteger('due_days')->default(0);
            $table->string('activity_type', 16)->default('task');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('crm_deal_automation_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rule_id')->constrained('crm_deal_automation_rules')->restrictOnDelete();
            $table->foreignId('history_id')->constrained('crm_deal_stage_histories')->restrictOnDelete();
            $table->json('configuration');
            $table->string('outcome', 30)->default('pending');
            $table->timestamp('scheduled_at');
            $table->timestamps();
            $table->unique(['rule_id', 'history_id']);
            $table->index(['outcome', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_deal_automation_executions');
        Schema::dropIfExists('crm_deal_automation_rules');
    }
};
