<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_cheques', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('replacement_of_id')->nullable()->constrained('lease_cheques')->nullOnDelete();
            $table->string('cheque_number');
            $table->string('bank_name');
            $table->string('payer_name');
            $table->decimal('amount', 16, 2);
            $table->date('due_on');
            $table->string('status')->default('scheduled');
            $table->date('deposited_on')->nullable();
            $table->date('cleared_on')->nullable();
            $table->date('bounced_on')->nullable();
            $table->text('bounce_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->unique(['organization_id', 'cheque_number'], 'lease_cheques_org_number_unique');
            $table->index(['organization_id', 'status', 'due_on'], 'lease_cheques_status_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_cheques');
    }
};
