<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offplan_project_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::table('offplan_projects', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
            $table->string('workflow_status', 40)->default('planning');
            $table->date('launch_on')->nullable();
            $table->date('handover_on')->nullable();
            $table->foreignId('assigned_broker_id')->nullable()->constrained('brokers')->nullOnDelete();
            $table->index(['organization_id', 'workflow_status']);
        });
    }

    public function down(): void
    {
        Schema::table('offplan_projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_broker_id');
            $table->dropIndex(['organization_id', 'workflow_status']);
            $table->dropColumn(['version', 'workflow_status', 'launch_on', 'handover_on']);
        });
        Schema::dropIfExists('offplan_project_statuses');
    }
};
