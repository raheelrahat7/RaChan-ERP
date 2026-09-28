<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 12)->default('normal');
            $table->string('status', 16)->default('open');
            $table->timestamp('due_at')->nullable();
            $table->string('related_type', 20)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'assigned_to', 'status', 'due_at'], 'work_task_assignee_due_idx');
            $table->index(['organization_id', 'related_type', 'related_id'], 'work_task_related_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_tasks');
    }
};
