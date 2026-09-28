<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cost_centre_id')->nullable()->constrained('accounting_cost_centres')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('maintenance_vendors')->restrictOnDelete();
            $table->string('name');
            $table->string('type', 30);
            $table->decimal('budget_aed', 16, 2)->default(0);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
        Schema::create('portal_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->restrictOnDelete();
            $table->string('portal', 30);
            $table->string('status', 24)->default('local_validated');
            $table->uuid('operation_key');
            $table->char('packet_sha256', 64);
            $table->timestamp('validated_at');
            $table->timestamps();
            $table->unique(['organization_id', 'listing_id', 'portal'], 'publication_listing_portal_unique');
            $table->unique(['organization_id', 'operation_key'], 'publication_operation_unique');
        });
        Schema::create('portal_enquiries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('publication_id')->constrained('portal_publications')->restrictOnDelete();
            $table->foreignId('lead_id')->constrained('crm_leads')->restrictOnDelete();
            $table->string('source_reference', 100);
            $table->timestamp('received_at');
            $table->timestamps();
            $table->unique(['organization_id', 'publication_id', 'source_reference'], 'portal_enquiry_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_enquiries');
        Schema::dropIfExists('portal_publications');
        Schema::dropIfExists('marketing_campaigns');
    }
};
