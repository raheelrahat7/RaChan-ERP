<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_report_filters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->json('filters');
            $table->timestamps();
            $table->unique(['organization_id', 'user_id', 'name'], 'saved_filters_owner_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_report_filters');
    }
};
