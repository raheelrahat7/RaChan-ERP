<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('crm_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('crm_accounts')->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'last_name']);
        });

        Schema::create('crm_leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->string('source')->nullable();
            $table->string('status')->default('new');
            $table->text('notes')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->foreignId('converted_account_id')->nullable()->constrained('crm_accounts')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('crm_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->morphs('subject');
            $table->string('type');
            $table->text('notes')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('crm_leads');
        Schema::dropIfExists('crm_contacts');
        Schema::dropIfExists('crm_accounts');
    }
};
