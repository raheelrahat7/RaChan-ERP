<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table): void {
            $table->boolean('requires_manager_confirmation')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table): void {
            $table->dropForeign(['submitted_by']);
            $table->dropForeign(['confirmed_by']);
            $table->dropForeign(['completed_by']);
            $table->dropColumn(['requires_manager_confirmation', 'submitted_at', 'submitted_by', 'confirmed_at', 'confirmed_by', 'completed_by']);
        });
    }
};
