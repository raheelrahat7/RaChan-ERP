<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_lead_import_batches', function (Blueprint $table) {
            $table->json('source_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_lead_import_batches', function (Blueprint $table) {
            $table->dropColumn('source_settings');
        });
    }
};
