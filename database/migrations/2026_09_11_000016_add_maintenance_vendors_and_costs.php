<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_vendors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('trade')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'name']);
        });
        Schema::table('maintenance_requests', function (Blueprint $table): void {
            $table->foreignId('vendor_id')->nullable()->after('assigned_to')->constrained('maintenance_vendors')->nullOnDelete();
            $table->decimal('estimated_cost', 16, 2)->nullable()->after('due_at');
            $table->decimal('actual_cost', 16, 2)->nullable()->after('estimated_cost');
            $table->char('currency', 3)->default('AED')->after('actual_cost');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropColumn(['estimated_cost', 'actual_cost', 'currency']);
        });
        Schema::dropIfExists('maintenance_vendors');
    }
};
