<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_ejari_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('renewal_of_id')->nullable()->constrained('lease_ejari_registrations')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('ejari_number')->nullable();
            $table->date('applied_on');
            $table->date('registered_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->unique(['organization_id', 'ejari_number'], 'lease_ejari_org_number_unique');
            $table->index(['organization_id', 'status', 'expires_on'], 'lease_ejari_status_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_ejari_registrations');
    }
};
