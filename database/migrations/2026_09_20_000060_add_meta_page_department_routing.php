<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_meta_pages', function (Blueprint $table): void {
            $table->foreignId('department_id')->nullable()->constrained('crm_departments')->nullOnDelete();
        });
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->string('meta_page_id', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', fn (Blueprint $table) => $table->dropColumn('meta_page_id'));
        Schema::table('crm_meta_pages', fn (Blueprint $table) => $table->dropConstrainedForeignId('department_id'));
    }
};
