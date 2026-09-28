<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table): void {
            $table->foreignId('broker_id')->nullable()->after('contact_id')->constrained()->nullOnDelete();
        });
        Schema::table('sales_contracts', function (Blueprint $table): void {
            $table->foreignId('broker_id')->nullable()->after('contact_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leases', fn (Blueprint $table) => $table->dropConstrainedForeignId('broker_id'));
        Schema::table('sales_contracts', fn (Blueprint $table) => $table->dropConstrainedForeignId('broker_id'));
    }
};
