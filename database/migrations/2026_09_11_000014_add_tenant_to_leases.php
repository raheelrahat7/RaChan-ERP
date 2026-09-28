<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('contact_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
