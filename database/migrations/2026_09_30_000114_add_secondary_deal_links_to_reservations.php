<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->foreignId('listing_id')->nullable()->after('unit_id')->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->after('listing_id')->constrained('crm_leads')->nullOnDelete();
            $table->index(['organization_id', 'listing_id']);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'listing_id']);
            $table->dropConstrainedForeignId('lead_id');
            $table->dropConstrainedForeignId('listing_id');
        });
    }
};
