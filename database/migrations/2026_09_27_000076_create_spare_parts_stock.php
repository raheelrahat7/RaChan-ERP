<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['spare_parts', 'stock_stores'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('code', 50);
                $table->string('name');
                if ($name === 'spare_parts') {
                    $table->string('unit', 30);
                }
                $table->timestamps();
                $table->unique(['organization_id', 'code']);
            });
        }
        Schema::create('stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spare_part_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_store_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quantity')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'spare_part_id', 'stock_store_id'], 'stock_balance_unique');
        });
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spare_part_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_store_id')->constrained()->restrictOnDelete();
            $table->foreignId('maintenance_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);
            $table->unsignedBigInteger('quantity');
            $table->bigInteger('delta');
            $table->string('reference');
            $table->text('reason')->nullable();
            $table->uuid('operation_key');
            $table->foreignId('related_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('reversed_by_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'operation_key'], 'stock_movement_operation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_stores');
        Schema::dropIfExists('spare_parts');
    }
};
