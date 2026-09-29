<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brokers', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('organization_id')->constrained('users')->nullOnDelete();
            $table->unique(['organization_id', 'user_id'], 'brokers_org_user_unique');
        });
    }

    public function down(): void
    {
        Schema::table('brokers', function (Blueprint $table): void {
            $table->dropUnique('brokers_org_user_unique');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
