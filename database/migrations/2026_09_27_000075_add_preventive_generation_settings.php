<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preventive_maintenance_plans', function (Blueprint $table): void {
            $table->boolean('auto_generate_enabled')->default(false);
            $table->boolean('requires_manager_confirmation')->default(false);
            $table->timestamp('automation_last_run_at')->nullable();
            $table->string('automation_last_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('preventive_maintenance_plans', function (Blueprint $table): void {
            $table->dropColumn(['auto_generate_enabled', 'requires_manager_confirmation', 'automation_last_run_at', 'automation_last_error']);
        });
    }
};
