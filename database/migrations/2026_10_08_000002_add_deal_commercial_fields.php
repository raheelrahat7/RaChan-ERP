<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_deals', function (Blueprint $table): void {
            $table->string('deal_status', 24)->nullable();
            $table->string('scenario', 24)->nullable();
            $table->decimal('gross_commission', 18, 2)->nullable();
            $table->decimal('co_broker_share', 5, 2)->nullable();
            $table->decimal('agent_share', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_deals', fn (Blueprint $table) => $table->dropColumn(['deal_status', 'scenario', 'gross_commission', 'co_broker_share', 'agent_share']));
    }
};
