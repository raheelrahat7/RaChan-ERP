<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table): void {
            $table->foreignId('property_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            $table->string('location')->nullable()->after('asset_class');
            $table->string('custodian')->nullable()->after('location');
        });

        Schema::create('fixed_asset_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->cascadeOnDelete();
            $table->date('transferred_on');
            $table->foreignId('from_property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('to_property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->string('from_location')->nullable();
            $table->string('to_location')->nullable();
            $table->string('from_custodian')->nullable();
            $table->string('to_custodian')->nullable();
            $table->string('from_asset_class');
            $table->string('to_asset_class');
            $table->text('reason');
            $table->foreignId('approved_by')->constrained('users');
            $table->timestamps();
            $table->index(['organization_id', 'transferred_on'], 'fa_transfers_org_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_transfers');
        Schema::table('fixed_assets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('property_id');
            $table->dropColumn(['location', 'custodian']);
        });
    }
};
