<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->decimal('valuation_price', 16, 2)->nullable();
            $table->string('mortgage_status', 60)->nullable();
            $table->string('noc_status', 60)->nullable();
            $table->string('transfer_status', 60)->nullable();
            $table->foreignId('buyer_contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->index(['organization_id', 'market_segment', 'workflow_status']);
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'market_segment', 'workflow_status']);
            $table->dropConstrainedForeignId('buyer_contact_id');
            $table->dropColumn(['valuation_price', 'mortgage_status', 'noc_status', 'transfer_status']);
        });
    }
};
