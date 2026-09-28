<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_meta_pages', function (Blueprint $table): void {
            $table->uuid('webhook_key')->nullable()->unique();
            $table->text('app_secret')->nullable();
            $table->text('verify_token')->nullable();
            $table->string('graph_version', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_meta_pages', function (Blueprint $table): void {
            $table->dropColumn(['webhook_key', 'app_secret', 'verify_token', 'graph_version']);
        });
    }
};
