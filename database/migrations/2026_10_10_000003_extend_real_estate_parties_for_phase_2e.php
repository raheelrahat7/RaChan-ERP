<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['owners', 'offplan_developers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedInteger('version')->default(1);
                $table->text('payment_terms')->nullable();
                $table->text('commission_notes')->nullable();
            });
        }

        Schema::table('listings', function (Blueprint $table): void {
            $table->foreignId('developer_id')->nullable()->constrained('offplan_developers')->nullOnDelete();
            $table->index(['organization_id', 'developer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'developer_id']);
            $table->dropConstrainedForeignId('developer_id');
        });

        foreach (['owners', 'offplan_developers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['version', 'payment_terms', 'commission_notes']);
            });
        }
    }
};
