<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->string('source_reference', 100)->nullable()->after('reference');
            $table->unique(['organization_id', 'source_reference'], 'journal_org_source_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropUnique('journal_org_source_ref_unique');
            $table->dropColumn('source_reference');
        });
    }
};
