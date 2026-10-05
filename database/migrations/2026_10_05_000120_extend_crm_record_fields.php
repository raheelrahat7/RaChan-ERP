<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_custom_fields', function (Blueprint $table): void {
            $table->string('entity', 16)->default('lead');
            $table->text('tooltip')->nullable();
            $table->boolean('show_in_filter')->default(true);
            $table->boolean('show_in_list')->default(false);
            $table->dropUnique(['organization_id', 'key']);
            $table->unique(['organization_id', 'entity', 'key']);
        });
        Schema::create('crm_record_field_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_id')->constrained('crm_custom_fields')->restrictOnDelete();
            $table->string('entity', 16);
            $table->unsignedBigInteger('record_id');
            $table->json('value');
            $table->timestamps();
            $table->unique(['entity', 'record_id', 'field_id'], 'crm_record_field_unique');
            $table->index(['organization_id', 'entity', 'record_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('crm_custom_fields')->where('entity', '!=', 'lead')->exists()) {
            throw new RuntimeException('Preserve/export non-lead field definitions before rolling back this migration.');
        }
        Schema::dropIfExists('crm_record_field_values');
        Schema::table('crm_custom_fields', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'entity', 'key']);
            $table->dropColumn(['entity', 'tooltip', 'show_in_filter', 'show_in_list']);
            $table->unique(['organization_id', 'key']);
        });
    }
};
