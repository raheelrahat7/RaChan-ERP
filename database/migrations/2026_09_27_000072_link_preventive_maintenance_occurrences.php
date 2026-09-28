<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table): void {
            $table->foreignId('preventive_maintenance_plan_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('preventive_due_on')->nullable();
            $table->unique(['preventive_maintenance_plan_id', 'preventive_due_on'], 'maintenance_plan_due_unique');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table): void {
            $table->dropForeign(['preventive_maintenance_plan_id']);
            $table->dropUnique('maintenance_plan_due_unique');
            $table->dropColumn(['preventive_maintenance_plan_id', 'preventive_due_on']);
        });
    }
};
