<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('pipeline_id');
            $table->unsignedBigInteger('stage_id')->nullable();
            $table->string('name');
            $table->string('trigger');
            $table->string('condition_field')->nullable();
            $table->string('condition_operator')->nullable();
            $table->string('condition_value')->nullable();
            $table->string('action');
            $table->unsignedSmallInteger('due_days')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'pipeline_id', 'stage_id', 'active'], 'crm_rule_lookup_idx');
        });
        Schema::create('crm_automation_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rule_id')->constrained('crm_automation_rules')->cascadeOnDelete();
            $table->unsignedBigInteger('stage_history_id');
            $table->string('outcome', 24);
            $table->timestamps();
            $table->unique(['rule_id', 'stage_history_id'], 'crm_rule_once_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_automation_executions');
        Schema::dropIfExists('crm_automation_rules');
    }
};
