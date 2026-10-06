<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['crm_working_calendars', 'organization_provider_profiles'] as $table) {
            Schema::table($table, fn (Blueprint $schema) => $schema->unsignedInteger('version')->default(1));
        }
    }

    public function down(): void
    {
        foreach (['crm_working_calendars', 'organization_provider_profiles'] as $table) {
            Schema::table($table, fn (Blueprint $schema) => $schema->dropColumn('version'));
        }
    }
};
