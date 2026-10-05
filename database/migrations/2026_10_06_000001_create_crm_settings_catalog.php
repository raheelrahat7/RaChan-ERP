<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_crm_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('code', 100);
            $table->string('name', 150);
            $table->json('settings')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(100);
            $table->timestamps();
            $table->unique(['organization_id', 'kind', 'code'], 'org_crm_settings_unique');
        });
        Schema::create('crm_lead_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('organization_crm_settings')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 2);
            $table->string('currency', 3)->default('AED');
            $table->timestamps();
            $table->unique(['lead_id', 'product_id']);
        });
        Schema::table('reference_workflow_records', function (Blueprint $table): void {
            $table->foreignId('lead_id')->nullable()->after('contact_id')->constrained('crm_leads')->restrictOnDelete();
            $table->index(['organization_id', 'lead_id']);
        });
    }

    public function down(): void
    {
        Schema::table('reference_workflow_records', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'lead_id']);
            $table->dropConstrainedForeignId('lead_id');
        });
        Schema::dropIfExists('crm_lead_products');
        Schema::dropIfExists('organization_crm_settings');
    }
};
