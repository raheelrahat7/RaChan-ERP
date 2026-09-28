<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('daily_digest_enabled')->default(true);
            $table->json('enabled_categories');
            $table->timestamps();
        });

        Schema::create('organization_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 64);
            $table->string('event_key', 100);
            $table->string('title');
            $table->unsignedInteger('count');
            $table->string('href');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id', 'event_key'], 'org_notify_user_event_unique');
            $table->index(['organization_id', 'user_id', 'read_at', 'created_at'], 'org_notify_user_read_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_notifications');
        Schema::dropIfExists('notification_preferences');
    }
};
