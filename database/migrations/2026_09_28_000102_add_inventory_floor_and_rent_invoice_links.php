<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table): void {
            $table->string('floor', 8)->nullable()->after('building_id');
            $table->index(['organization_id', 'building_id', 'floor'], 'units_building_floor_idx');
        });

        Schema::create('lease_rent_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('linked_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamps();
            $table->index(['organization_id', 'lease_id'], 'rent_invoices_org_lease_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_rent_invoices');
        Schema::table('units', function (Blueprint $table): void {
            $table->dropIndex('units_building_floor_idx');
            $table->dropColumn('floor');
        });
    }
};
