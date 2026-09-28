<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_id')->constrained()->restrictOnDelete();
            $table->foreignId('commission_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('source');
            $table->decimal('base_amount', 16, 2);
            $table->decimal('commission_amount', 16, 2);
            $table->char('currency', 3)->default('AED');
            $table->string('status')->default('calculated');
            $table->date('payable_on')->nullable();
            $table->date('paid_on')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_transactions');
    }
};
