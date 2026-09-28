<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_security_deposits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('required_amount', 16, 2);
            $table->date('due_on');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_security_deposits');
    }
};
