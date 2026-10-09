<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
            $table->string('tenancy_number', 100)->nullable();
            $table->date('renewal_due_on')->nullable();
            $table->date('last_renewed_on')->nullable();
            $table->decimal('advance_amount', 16, 2)->nullable();
            $table->unique(['organization_id', 'tenancy_number']);
            $table->index(['organization_id', 'status', 'renewal_due_on']);
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'tenancy_number']);
            $table->dropIndex(['organization_id', 'status', 'renewal_due_on']);
            $table->dropColumn(['version', 'tenancy_number', 'renewal_due_on', 'last_renewed_on', 'advance_amount']);
        });
    }
};
