<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_spend', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->restrictOnDelete();
            $table->foreignId('publication_id')->nullable()->constrained('portal_publications')->restrictOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->restrictOnDelete();
            $table->foreignId('vendor_bill_id')->nullable()->constrained('vendor_bills')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('channel', 30);
            $table->string('source', 16);
            $table->decimal('amount_aed', 16, 2);
            $table->date('incurred_on');
            $table->text('reason');
            $table->timestamps();
            $table->index(['organization_id', 'listing_id', 'channel'], 'listing_spend_channel_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_spend');
    }
};
