<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gaim_routing_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('emirate', 40);
            $table->string('event', 30);
            $table->string('authority', 100);
            $table->string('form_code', 100);
            $table->string('form_name');
            $table->boolean('required')->default(true);
            $table->boolean('active')->default(true);
            $table->date('effective_from')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'emirate', 'event', 'authority', 'form_code'], 'gaim_rule_unique');
        });
        Schema::create('gaim_compliance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('routing_rule_id')->constrained('gaim_routing_rules')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id');
            $table->string('status', 30)->default('required');
            $table->string('authority_reference', 150)->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'routing_rule_id', 'subject_type', 'subject_id'], 'gaim_record_subject_unique');
            $table->index(['organization_id', 'status', 'expires_on'], 'gaim_record_status_expiry_idx');
        });
        Schema::create('gaim_record_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('record_id')->constrained('gaim_compliance_records')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['record_id', 'occurred_at'], 'gaim_event_record_time_idx');
        });
        Schema::create('gaim_bulletins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('authority', 100);
            $table->string('title');
            $table->text('body');
            $table->json('affected_forms');
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'created_at'], 'gaim_bulletin_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gaim_bulletins');
        Schema::dropIfExists('gaim_record_events');
        Schema::dropIfExists('gaim_compliance_records');
        Schema::dropIfExists('gaim_routing_rules');
    }
};
