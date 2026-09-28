<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('corporate_tax_profile')->nullable()->after('vat_return_frequency');
            $table->unsignedTinyInteger('corporate_tax_year_start_month')->default(1)->after('corporate_tax_profile');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['corporate_tax_profile', 'corporate_tax_year_start_month']));
    }
};
