<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_staff', function (Blueprint $table): void {
            $table->date('dismissed_on')->nullable()->after('hired_on');
            $table->text('dismissal_reason')->nullable()->after('dismissed_on');
        });
    }

    public function down(): void
    {
        Schema::table('hr_staff', fn (Blueprint $table) => $table->dropColumn(['dismissed_on', 'dismissal_reason']));
    }
};
