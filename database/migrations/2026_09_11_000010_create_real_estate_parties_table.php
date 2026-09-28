<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['owners', 'tenants', 'brokers'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('reference')->nullable();
                $table->timestamps();
                $table->index(['organization_id', 'name']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brokers');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('owners');
    }
};
