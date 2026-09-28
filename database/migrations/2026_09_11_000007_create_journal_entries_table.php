<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->string('event');
            $table->nullableMorphs('subject');
            $table->date('posted_on');
            $table->decimal('debit_total', 16, 2);
            $table->decimal('credit_total', 16, 2);
            $table->char('currency', 3)->default('AED');
            $table->timestamps();
            $table->index(['organization_id', 'posted_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
