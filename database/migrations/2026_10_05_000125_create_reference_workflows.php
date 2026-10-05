<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_workflow_pipelines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(100);
            $table->json('read_roles');
            $table->json('edit_roles');
            $table->json('field_definitions')->nullable();
            $table->timestamps();
        });
        Schema::create('reference_workflow_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('reference_workflow_pipelines')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('type', 16)->default('normal');
            $table->string('color', 7)->default('#38bdf8');
            $table->unsignedInteger('position')->default(100);
            $table->boolean('active')->default(true);
            $table->boolean('is_initial')->default(false);
            $table->json('allowed_from_stage_ids')->nullable();
            $table->json('entry_roles')->nullable();
            $table->json('required_fields')->nullable();
            $table->json('source_statuses')->nullable();
            $table->timestamps();
        });
        Schema::create('reference_workflow_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained('reference_workflow_pipelines')->restrictOnDelete();
            $table->foreignId('stage_id')->constrained('reference_workflow_stages')->restrictOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 100);
            $table->string('title', 255);
            $table->json('details');
            $table->foreignId('document_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('stage_changed_at');
            $table->timestamp('closed_at')->nullable();
            $table->uuid('operation_key');
            $table->timestamps();
            $table->unique(['organization_id', 'operation_key']);
            $table->unique(['organization_id', 'reference']);
        });
        Schema::create('reference_workflow_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('record_id')->constrained('reference_workflow_records')->restrictOnDelete();
            $table->foreignId('from_stage_id')->nullable()->constrained('reference_workflow_stages')->restrictOnDelete();
            $table->foreignId('to_stage_id')->constrained('reference_workflow_stages')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('snapshot');
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['reference_workflow_histories', 'reference_workflow_records', 'reference_workflow_stages', 'reference_workflow_pipelines'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
