<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('headers');
            $table->longText('rows');
            $table->string('source_hash', 64);
            $table->json('summary')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'user_id', 'created_at'], 'crm_import_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_import_batches');
    }
};
