<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('document_id')->constrained()->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->uuid('operation_key');
            $t->unsignedInteger('version_number');
            $t->string('content_hash', 64);
            $t->json('signers');
            $t->text('reason');
            $t->string('status', 20)->default('local_prepared');
            $t->timestamp('cancelled_at')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('cancellation_reason')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'operation_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_requests');
    }
};
