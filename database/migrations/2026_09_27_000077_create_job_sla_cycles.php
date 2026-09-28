<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_sla_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('maintenance_request_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('cycle_number');
            $table->string('timezone', 100);
            $table->json('working_days');
            $table->json('holidays');
            $table->unsignedInteger('response_seconds');
            $table->unsignedInteger('resolution_seconds');
            $table->timestamp('started_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('holds');
            $table->timestamp('held_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('outcome')->nullable();
            $table->timestamps();
            $table->unique(['maintenance_request_id', 'cycle_number']);
            $table->index(['organization_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_sla_cycles');
    }
};
