<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('root_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->unsignedInteger('version_number')->default(1);
            $table->text('version_reason')->nullable();
            $table->uuid('version_key')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('archive_reason')->nullable();
            $table->unique(['organization_id', 'version_key'], 'document_version_key_unique');
            $table->unique(['root_document_id', 'version_number'], 'document_series_version_unique');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropUnique('document_version_key_unique');
            $table->dropUnique('document_series_version_unique');
            $table->dropConstrainedForeignId('root_document_id');
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn(['version_number', 'version_reason', 'version_key', 'content_hash', 'archived_at', 'archive_reason']);
        });
    }
};
