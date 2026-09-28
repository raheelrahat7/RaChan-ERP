<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_import_batches', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('kind', 20);
            $t->json('rows');
            $t->json('errors');
            $t->string('source_hash', 64);
            $t->timestamp('expires_at');
            $t->timestamp('committed_at')->nullable();
            $t->json('created_ids')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_import_batches');
    }
};
