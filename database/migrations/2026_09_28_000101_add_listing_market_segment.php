<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            // Historical listings are intentionally unclassified.
            $table->string('market_segment', 16)->nullable()->after('purpose');
            $table->index(['organization_id', 'purpose', 'market_segment'], 'listing_market_segment_idx');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropIndex('listing_market_segment_idx');
            $table->dropColumn('market_segment');
        });
    }
};
