<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_chat_rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('name', 120)->nullable();
            $table->string('room_key', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'room_key'], 'chat_room_key_unique');
            $table->index(['organization_id', 'kind']);
        });
        Schema::create('internal_chat_members', function (Blueprint $table): void {
            $table->foreignId('room_id')->constrained('internal_chat_rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('joined_at');
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->primary(['room_id', 'user_id']);
        });
        Schema::create('internal_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('internal_chat_rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['room_id', 'id']);
        });
        Schema::create('internal_chat_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('internal_chat_rooms')->cascadeOnDelete();
            $table->foreignId('initiator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('ringing');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['room_id', 'status']);
        });
        Schema::create('internal_chat_call_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_id')->constrained('internal_chat_calls')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 16);
            $table->text('payload');
            $table->timestamp('created_at');
            $table->index(['call_id', 'recipient_id', 'id'], 'chat_call_signal_poll_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_chat_call_signals');
        Schema::dropIfExists('internal_chat_calls');
        Schema::dropIfExists('internal_chat_messages');
        Schema::dropIfExists('internal_chat_members');
        Schema::dropIfExists('internal_chat_rooms');
    }
};
