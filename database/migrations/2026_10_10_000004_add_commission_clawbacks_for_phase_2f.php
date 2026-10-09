<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_transactions', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
        });

        Schema::create('commission_clawbacks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commission_transaction_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 16, 2);
            $table->string('reason', 2000);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'commission_transaction_id'], 'commission_clawbacks_org_commission_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_clawbacks');
        Schema::table('commission_transactions', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
