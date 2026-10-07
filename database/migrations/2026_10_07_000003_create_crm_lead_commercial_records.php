<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_commercial_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('crm_leads')->restrictOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained('crm_deals')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('reference', 100)->nullable();
            $table->string('title');
            $table->string('party_name')->nullable();
            $table->string('status', 60);
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->date('submitted_on')->nullable();
            $table->date('signed_on')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'lead_id', 'kind'], 'lead_commercial_org_lead_kind_idx');
        });

        Schema::create('crm_lead_commercial_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('statuses');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_commercial_settings');
        Schema::dropIfExists('crm_lead_commercial_records');
    }
};
