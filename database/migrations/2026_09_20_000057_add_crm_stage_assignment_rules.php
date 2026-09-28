<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_pipeline_stages', function (Blueprint $table): void {
            $table->json('assignment_member_ids')->nullable();
            $table->foreignId('assignment_last_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_pipeline_stages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assignment_last_user_id');
            $table->dropColumn('assignment_member_ids');
        });
    }
};
