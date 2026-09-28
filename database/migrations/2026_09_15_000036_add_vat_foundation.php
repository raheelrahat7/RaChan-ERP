<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->boolean('vat_enabled')->default(false)->after('slug');
            $table->string('tax_registration_number', 15)->nullable()->after('vat_enabled');
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('vat_treatment')->nullable()->after('accounting_treatment');
            $table->decimal('vat_rate', 5, 2)->nullable()->after('vat_treatment');
            $table->decimal('vat_amount', 16, 2)->nullable()->after('vat_rate');
        });
        Schema::table('vendor_bills', function (Blueprint $table): void {
            $table->string('vat_treatment')->nullable()->after('accounting_treatment');
            $table->decimal('vat_rate', 5, 2)->nullable()->after('vat_treatment');
            $table->decimal('vat_amount', 16, 2)->nullable()->after('vat_rate');
            $table->boolean('input_vat_recoverable')->nullable()->after('vat_amount');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_bills', fn (Blueprint $table) => $table->dropColumn(['vat_treatment', 'vat_rate', 'vat_amount', 'input_vat_recoverable']));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['vat_treatment', 'vat_rate', 'vat_amount']));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['vat_enabled', 'tax_registration_number']));
    }
};
