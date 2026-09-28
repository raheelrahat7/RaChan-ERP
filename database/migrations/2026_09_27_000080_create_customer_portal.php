<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('email');
            $table->string('role', 20);
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('portal_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('portal_invitation_id')->unique()->constrained()->restrictOnDelete();
            $table->string('role', 20);
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'user_id', 'revoked_at']);
        });
        Schema::create('portal_lease_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('lease_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('portal_service_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('portal_grant_id')->constrained()->restrictOnDelete();
            $table->foreignId('lease_id')->constrained()->restrictOnDelete();
            $table->foreignId('maintenance_request_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('operation_key');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 20);
            $table->timestamps();
            $table->unique(['organization_id', 'operation_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_service_requests');
        Schema::dropIfExists('portal_lease_invoices');
        Schema::dropIfExists('portal_grants');
        Schema::dropIfExists('portal_invitations');
    }
};
