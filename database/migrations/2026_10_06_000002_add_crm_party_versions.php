<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['crm_contacts', 'crm_accounts'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->unsignedInteger('version')->default(1));
        }
        Schema::table('organization_crm_settings', fn (Blueprint $table) => $table->unsignedInteger('version')->default(1));
    }

    public function down(): void
    {
        foreach (['crm_contacts', 'crm_accounts'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('version'));
        }
        Schema::table('organization_crm_settings', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
