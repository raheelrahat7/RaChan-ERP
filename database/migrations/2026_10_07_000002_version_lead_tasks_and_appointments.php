<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_tasks', fn (Blueprint $table) => $table->unsignedInteger('version')->default(1));
        Schema::table('brokerage_appointments', fn (Blueprint $table) => $table->unsignedInteger('version')->default(1));
        Schema::table('brokerage_appointments', fn (Blueprint $table) => $table->index(['organization_id', 'lead_id', 'starts_at'], 'appointments_org_lead_start_idx'));
    }

    public function down(): void
    {
        Schema::table('brokerage_appointments', fn (Blueprint $table) => $table->dropIndex('appointments_org_lead_start_idx'));
        Schema::table('brokerage_appointments', fn (Blueprint $table) => $table->dropColumn('version'));
        Schema::table('work_tasks', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
