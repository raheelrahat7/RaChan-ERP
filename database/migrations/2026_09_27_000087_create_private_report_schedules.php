<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_report_schedules', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('name', 100);
            $t->json('filters');
            $t->string('format', 4);
            $t->string('frequency', 7);
            $t->string('local_time', 5);
            $t->unsignedTinyInteger('weekday')->default(1);
            $t->boolean('enabled')->default(true);
            $t->timestamp('next_run_at');
            $t->timestamps();
        });
        Schema::create('private_report_deliveries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('private_report_schedule_id')->constrained()->restrictOnDelete();
            $t->timestamp('scheduled_for');
            $t->timestamp('generated_at')->nullable();
            $t->string('format', 4);
            $t->string('status', 20);
            $t->string('path')->nullable();
            $t->json('job_ids')->nullable();
            $t->string('failure_reason')->nullable();
            $t->timestamps();
            $t->unique(['private_report_schedule_id', 'scheduled_for'], 'private_report_occurrence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('private_report_deliveries');
        Schema::dropIfExists('private_report_schedules');
    }
};
