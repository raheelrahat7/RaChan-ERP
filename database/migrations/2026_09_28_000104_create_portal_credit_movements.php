<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_credit_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('portal_subscriptions')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->integer('delta');
            $table->string('source_reference', 100);
            $table->text('reason');
            $table->timestamp('recorded_at');
            $table->unique(['subscription_id', 'source_reference'], 'portal_credit_source_unique');
            $table->index(['organization_id', 'subscription_id', 'recorded_at'], 'portal_credit_org_subscription_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_credit_movements');
    }
};
