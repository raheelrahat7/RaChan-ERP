<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_deposit_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_security_deposit_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('draft');
            $table->decimal('collected_amount', 16, 2)->nullable();
            $table->decimal('deductions_total', 16, 2)->nullable();
            $table->decimal('refund_amount', 16, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('submitted_by')->nullable()->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status'], 'deposit_settlements_org_status_idx');
        });

        Schema::create('lease_deposit_deductions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_deposit_settlement_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('description');
            $table->decimal('amount', 16, 2);
            $table->text('evidence')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_deposit_deductions');
        Schema::dropIfExists('lease_deposit_settlements');
    }
};
