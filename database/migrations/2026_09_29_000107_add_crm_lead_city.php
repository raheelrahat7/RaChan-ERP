<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->string('city')->nullable()->after('company');
            $table->index(['organization_id', 'city'], 'crm_leads_org_city_idx');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropIndex('crm_leads_org_city_idx');
            $table->dropColumn('city');
        });
    }
};
