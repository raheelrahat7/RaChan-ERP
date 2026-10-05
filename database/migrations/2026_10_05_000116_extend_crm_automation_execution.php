<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_automation_rules', function (Blueprint $table) {
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->string('activity_type', 20)->default('task');
            $table->unsignedBigInteger('target_stage_id')->nullable();
        });
        Schema::table('crm_automation_executions', function (Blueprint $table) {
            $table->dateTime('scheduled_at')->nullable()->index();
            $table->json('configuration')->nullable();
            $table->string('failure_reason', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_automation_executions', function (Blueprint $table) {
            $table->dropIndex(['scheduled_at']);
            $table->dropColumn(['scheduled_at', 'configuration', 'failure_reason']);
        });
        Schema::table('crm_automation_rules', function (Blueprint $table) {
            $table->dropColumn(['delay_minutes', 'activity_type', 'target_stage_id']);
        });
    }
};
