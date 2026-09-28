<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('accounting_companies')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('accounting_branches')->restrictOnDelete();
            $table->foreignId('cost_centre_id')->nullable()->constrained('accounting_cost_centres')->restrictOnDelete();
            $table->string('portal', 30);
            $table->string('package');
            $table->decimal('contract_value_aed', 16, 2);
            $table->string('billing_cycle', 20);
            $table->unsignedInteger('credits_total')->default(0);
            $table->unsignedInteger('credits_used')->default(0);
            $table->date('starts_on');
            $table->date('renews_on')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['organization_id', 'portal', 'status'], 'subscription_portal_status_idx');
        });
        Schema::create('portal_subscription_bills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('portal_subscriptions')->restrictOnDelete();
            $table->foreignId('vendor_bill_id')->unique()->constrained('vendor_bills')->restrictOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->timestamps();
            $table->index(['subscription_id', 'period_from'], 'subscription_bill_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_subscription_bills');
        Schema::dropIfExists('portal_subscriptions');
    }
};
