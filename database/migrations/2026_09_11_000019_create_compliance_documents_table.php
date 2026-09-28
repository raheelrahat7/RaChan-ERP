<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->morphs('documentable');
            $table->string('name');
            $table->string('category');
            $table->date('expires_on')->nullable();
            $table->string('path');
            $table->timestamps();
            $table->index(['organization_id', 'expires_on'], 'compliance_docs_org_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_documents');
    }
};
