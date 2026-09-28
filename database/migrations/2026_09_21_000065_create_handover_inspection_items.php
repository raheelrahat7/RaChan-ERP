<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handover_inspection_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('handover_checklist_id')->constrained()->cascadeOnDelete();
            $table->string('area', 100);
            $table->string('condition', 30);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'handover_checklist_id'], 'handover_inspection_org_handover_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handover_inspection_items');
    }
};
