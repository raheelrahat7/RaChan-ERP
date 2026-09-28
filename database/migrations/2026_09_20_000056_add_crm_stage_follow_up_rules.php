<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_pipeline_stages', function (Blueprint $table): void {
            $table->unsignedSmallInteger('follow_up_due_days')->nullable();
        });
        Schema::table('crm_activities', function (Blueprint $table): void {
            $table->foreignId('stage_history_id')->nullable()->unique()->constrained('crm_lead_stage_histories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_activities', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stage_history_id');
        });
        Schema::table('crm_pipeline_stages', function (Blueprint $table): void {
            $table->dropColumn('follow_up_due_days');
        });
    }
};
