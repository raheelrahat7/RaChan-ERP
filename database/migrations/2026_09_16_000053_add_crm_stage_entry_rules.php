<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_pipeline_stages', function (Blueprint $table): void {
            $table->json('allowed_from_stage_ids')->nullable();
            $table->json('entry_roles')->nullable();
            $table->json('required_fields')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_pipeline_stages', fn (Blueprint $table) => $table->dropColumn(['allowed_from_stage_ids', 'entry_roles', 'required_fields']));
    }
};
