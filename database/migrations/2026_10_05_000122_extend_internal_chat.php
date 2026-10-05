<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_chat_mentions', function (Blueprint $table): void {
            $table->foreignId('message_id')->constrained('internal_chat_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['message_id', 'user_id']);
            $table->index('user_id');
        });
        Schema::create('internal_chat_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('internal_chat_rooms')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('internal_chat_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('path');
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->string('kind', 12);
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['message_id']);
            $table->index(['organization_id', 'room_id']);
        });
        Schema::table('internal_chat_calls', function (Blueprint $table): void {
            $table->string('kind', 8)->default('audio')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('internal_chat_calls', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
        Schema::dropIfExists('internal_chat_attachments');
        Schema::dropIfExists('internal_chat_mentions');
    }
};
