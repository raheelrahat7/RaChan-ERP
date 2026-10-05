<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_deal_pipelines', function (Blueprint $table): void {
            $table->json('lead_field_mapping')->nullable();
        });
        Schema::table('crm_deal_automation_rules', function (Blueprint $table): void {
            $table->json('conditions')->nullable();
            $table->boolean('working_hours_only')->default(false);
            $table->foreignId('target_stage_id')->nullable()->constrained('crm_deal_stages')->restrictOnDelete();
        });
        Schema::table('crm_deal_stages', fn (Blueprint $table) => $table->string('financial_requirement', 32)->nullable());
        Schema::create('crm_working_calendars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('working_days');
            $table->json('holidays');
            $table->timestamps();
        });
        Schema::create('crm_deal_financial_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->constrained('crm_deals')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('commission_id')->nullable()->constrained('commission_transactions')->restrictOnDelete();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['deal_id', 'invoice_id']);
            $table->unique(['deal_id', 'commission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_deal_financial_links');
        Schema::dropIfExists('crm_working_calendars');
        Schema::table('crm_deal_stages', fn (Blueprint $table) => $table->dropColumn('financial_requirement'));
        Schema::table('crm_deal_automation_rules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('target_stage_id');
            $table->dropColumn(['conditions', 'working_hours_only']);
        });
        Schema::table('crm_deal_pipelines', fn (Blueprint $table) => $table->dropColumn('lead_field_mapping'));
    }
};
