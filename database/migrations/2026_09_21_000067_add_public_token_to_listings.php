<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->string('public_token', 64)->nullable()->unique()->after('reference');
        });

        DB::table('listings')->orderBy('id')->each(function (object $listing): void {
            DB::table('listings')->where('id', $listing->id)->update(['public_token' => Str::random(48)]);
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
