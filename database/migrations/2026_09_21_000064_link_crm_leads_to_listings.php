<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->foreignId('listing_id')->nullable()->constrained('listings')->nullOnDelete();
            $table->index(['organization_id', 'listing_id']);
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'listing_id']);
            $table->dropConstrainedForeignId('listing_id');
        });
    }
};
