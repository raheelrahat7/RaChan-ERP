<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_receipt_costs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_receipt_costs');
    }
};
