<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('status')->default('draft');
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->char('currency', 3)->default('AED');
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 16, 2);
            $table->decimal('line_total', 16, 2);
            $table->timestamps();
        });
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->decimal('amount', 16, 2);
            $table->char('currency', 3)->default('AED');
            $table->date('received_on');
            $table->string('method')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
