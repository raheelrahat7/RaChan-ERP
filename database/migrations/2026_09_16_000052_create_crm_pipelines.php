<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_pipelines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('crm_pipeline_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('crm_pipelines')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 20)->default('normal');
            $table->string('color', 7)->default('#64748b');
            $table->unsignedInteger('position');
            $table->boolean('active')->default(true);
            $table->boolean('is_initial')->default(false);
            $table->timestamps();
        });
        Schema::create('crm_lost_reasons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('crm_pipelines')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('position');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->foreignId('pipeline_id')->nullable()->constrained('crm_pipelines')->restrictOnDelete();
            $table->foreignId('current_stage_id')->nullable()->constrained('crm_pipeline_stages')->restrictOnDelete();
            $table->foreignId('lost_reason_id')->nullable()->constrained('crm_lost_reasons')->restrictOnDelete();
            $table->timestamp('stage_changed_at')->nullable();
            $table->index(['organization_id', 'pipeline_id', 'current_stage_id'], 'crm_lead_pipeline_idx');
        });
        Schema::create('crm_lead_stage_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('crm_leads')->restrictOnDelete();
            $table->foreignId('pipeline_id')->constrained('crm_pipelines')->restrictOnDelete();
            $table->foreignId('from_stage_id')->nullable()->constrained('crm_pipeline_stages')->restrictOnDelete();
            $table->foreignId('to_stage_id')->constrained('crm_pipeline_stages')->restrictOnDelete();
            $table->foreignId('lost_reason_id')->nullable()->constrained('crm_lost_reasons')->restrictOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->json('snapshot');
            $table->text('notes')->nullable();
            $table->index(['lead_id', 'changed_at']);
        });

        // Preserve unfamiliar legacy statuses as named stages rather than discarding them.
        DB::table('organizations')->orderBy('id')->each(function ($organization): void {
            $pipeline = DB::table('crm_pipelines')->insertGetId(['organization_id' => $organization->id, 'name' => 'Sales Pipeline', 'active' => true, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
            $stages = [];
            foreach (['New Leads', 'Assigned Leads', 'Not Qualified', 'Qualified', 'Referral Leads', 'Lead on Hold', 'Won', 'Lost'] as $position => $name) {
                $type = match ($name) {
                    'Won' => 'won', 'Lost' => 'lost', 'Lead on Hold' => 'on_hold', default => 'normal'
                };
                $stages[$name] = DB::table('crm_pipeline_stages')->insertGetId(['pipeline_id' => $pipeline, 'name' => $name, 'type' => $type, 'color' => '#64748b', 'position' => $position + 1, 'active' => true, 'is_initial' => $position === 0, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach (['No Finance', 'Incorrect Number', 'Already Purchased', 'No Longer Interested', 'Agent Inquiry', 'Went Quiet'] as $position => $name) {
                DB::table('crm_lost_reasons')->insert(['pipeline_id' => $pipeline, 'name' => $name, 'position' => $position + 1, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('crm_leads')->where('organization_id', $organization->id)->orderBy('id')->each(function ($lead) use ($organization, $pipeline, &$stages): void {
                $name = $lead->converted_at !== null ? 'Won' : ($lead->status === 'new' ? 'New Leads' : 'Legacy: '.$lead->status);
                if (! isset($stages[$name])) {
                    $stages[$name] = DB::table('crm_pipeline_stages')->insertGetId(['pipeline_id' => $pipeline, 'name' => $name, 'type' => 'normal', 'color' => '#64748b', 'position' => count($stages) + 1, 'active' => true, 'is_initial' => false, 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('crm_leads')->where('id', $lead->id)->update(['pipeline_id' => $pipeline, 'current_stage_id' => $stages[$name], 'stage_changed_at' => now()]);
                DB::table('crm_lead_stage_histories')->insert(['organization_id' => $organization->id, 'lead_id' => $lead->id, 'pipeline_id' => $pipeline, 'to_stage_id' => $stages[$name], 'changed_at' => now(), 'snapshot' => json_encode(['pipeline' => 'Sales Pipeline', 'from' => null, 'to' => $name, 'to_type' => $lead->converted_at !== null ? 'won' : 'normal', 'lost_reason' => null, 'legacy_status' => $lead->status]), 'notes' => 'Initial pipeline migration; original stage entry time is unknown.']);
            });
        });
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->foreignId('pipeline_id')->nullable(false)->change();
            $table->foreignId('current_stage_id')->nullable(false)->change();
            $table->timestamp('stage_changed_at')->nullable(false)->change();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_stage_histories');
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropForeign(['pipeline_id']);
            $table->dropForeign(['current_stage_id']);
            $table->dropForeign(['lost_reason_id']);
            $table->dropIndex('crm_lead_pipeline_idx');
            $table->dropColumn(['pipeline_id', 'current_stage_id', 'lost_reason_id', 'stage_changed_at']);
        });
        Schema::dropIfExists('crm_lost_reasons');
        Schema::dropIfExists('crm_pipeline_stages');
        Schema::dropIfExists('crm_pipelines');
    }
};
