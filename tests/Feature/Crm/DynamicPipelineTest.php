<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Actions\InitializePipelines;
use App\Domain\Crm\Models\LeadStageHistory;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DynamicPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $org, OrganizationRole $role = OrganizationRole::Owner): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    private function pipeline(Organization $org): Pipeline
    {
        return Pipeline::where('organization_id', $org->id)->where('is_default', true)->sole();
    }

    private function stageData($stage, array $overrides = []): array
    {
        return [...$stage->only('name', 'description', 'position', 'type', 'color', 'active'), ...$overrides];
    }

    public function test_defaults_and_configuration_permissions_are_scoped(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $pipeline = $this->pipeline($org);
        $this->assertCount(8, $pipeline->stages);
        $this->assertCount(6, $pipeline->reasons);
        app(InitializePipelines::class)->handle($org);
        $this->assertSame(1, Pipeline::where('organization_id', $org->id)->count());
        $owner = $this->member($org);
        $this->actingAs($owner)->get(route('crm.pipelines.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('pipelines', 1)->where('pipelines.0.id', $pipeline->id));
        $this->post(route('crm.pipelines.store'), ['name' => 'Renewals', 'active' => true])->assertRedirect();
        $new = Pipeline::where('name', 'Renewals')->sole();
        $this->assertTrue($new->stages()->sole()->is_initial);
        $this->post(route('crm.leads.store'), ['first_name' => 'Selected', 'last_name' => 'Pipeline', 'pipeline_id' => $new->id])->assertRedirect();
        $this->assertSame($new->id, $org->leads()->sole()->pipeline_id);
        $this->post(route('crm.leads.convert', $org->leads()->sole()))->assertSessionHasErrors('stage_id');
        $this->assertDatabaseCount('crm_contacts', 0);
        $this->put(route('crm.pipelines.update', $new), ['name' => $new->name, 'active' => false])->assertRedirect();
        $this->post(route('crm.leads.store'), ['first_name' => 'Inactive', 'last_name' => 'Pipeline', 'pipeline_id' => $new->id])->assertSessionHasErrors('pipeline_id');
        $this->delete(route('crm.configuration.destroy', ['pipeline', $new->id]))->assertSessionHasErrors('configuration');
        $this->post(route('crm.leads.store'), ['first_name' => 'Foreign', 'last_name' => 'Pipeline', 'pipeline_id' => $this->pipeline($other)->id])->assertSessionHasErrors('pipeline_id');
        $this->put(route('crm.pipelines.update', $this->pipeline($other)), ['name' => 'Changed', 'active' => true])->assertNotFound();
        $this->put(route('crm.pipelines.update', $pipeline), ['name' => 'Sales', 'active' => false])->assertSessionHasErrors('active');
        $this->actingAs($this->member($org, OrganizationRole::Manager))->get(route('crm.pipelines.index'))->assertForbidden();
        $this->post(route('crm.pipelines.store'), ['name' => 'Unauthorized', 'active' => true])->assertForbidden();
        $this->actingAs($this->member($org, OrganizationRole::Administrator))->get(route('crm.pipelines.index'))->assertOk();
    }

    public function test_loss_reopening_and_history_preserve_outcomes_and_names(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $pipeline = $this->pipeline($org);
        $lost = $pipeline->stages->firstWhere('type', 'lost');
        $qualified = $pipeline->stages->firstWhere('name', 'Qualified');
        $won = $pipeline->stages->firstWhere('type', 'won');
        $reason = $pipeline->reasons->first();
        $this->actingAs($owner)->post(route('crm.leads.store'), ['first_name' => 'Test', 'last_name' => 'Lead'])->assertRedirect();
        $lead = $org->leads()->sole();
        $initial = $lead->current_stage_id;
        $url = route('crm.leads.stage', $lead);
        $this->put($url, ['stage_id' => $lost->id, 'expected_stage_id' => $initial])->assertSessionHasErrors('lost_reason_id');
        $this->assertSame($initial, $lead->fresh()->current_stage_id);
        $this->assertSame(1, $lead->history()->count());
        $foreignReason = $this->pipeline(Organization::factory()->create())->reasons()->first();
        $this->put($url, ['stage_id' => $lost->id, 'expected_stage_id' => $initial, 'lost_reason_id' => $foreignReason->id])->assertSessionHasErrors('lost_reason_id');
        $this->put($url, ['stage_id' => $lost->id, 'expected_stage_id' => $initial, 'lost_reason_id' => $reason->id, 'notes' => 'Customer declined'])->assertRedirect();
        $this->assertSame($reason->id, $lead->fresh()->lost_reason_id);
        $this->delete(route('crm.configuration.destroy', ['reason', $reason->id]))->assertSessionHasErrors('configuration');
        $this->post(route('crm.leads.convert', $lead))->assertSessionHasErrors('stage_id');
        $this->put($url, ['stage_id' => $won->id, 'expected_stage_id' => $lost->id])->assertSessionHasErrors('stage_id');
        $this->put(route('crm.pipeline-stages.update', [$pipeline, $lost]), $this->stageData($lost, ['name' => 'Closed unsuccessful']))->assertRedirect();
        $this->put(route('crm.lost-reasons.update', [$pipeline, $reason]), [...$reason->only('name', 'description', 'position', 'active'), 'name' => 'Funding unavailable'])->assertRedirect();
        $this->put($url, ['stage_id' => $qualified->id, 'expected_stage_id' => $lost->id])->assertRedirect();
        $this->assertNull($lead->fresh()->lost_reason_id);
        $this->delete(route('crm.configuration.destroy', ['reason', $reason->id]))->assertSessionHasErrors('configuration');
        $this->put(route('crm.lost-reasons.update', [$pipeline, $reason]), [...$reason->only('name', 'description', 'position', 'active'), 'active' => false])->assertRedirect();
        $this->put($url, ['stage_id' => $lost->id, 'expected_stage_id' => $qualified->id, 'lost_reason_id' => $reason->id])->assertSessionHasErrors('lost_reason_id');
        $history = $lead->history()->orderBy('id')->get();
        $loss = $history->firstWhere('lost_reason_id', $reason->id);
        $this->assertSame('Lost', $loss->snapshot['to']);
        $this->assertSame('No Finance', $loss->snapshot['lost_reason']);
        $this->assertSame($owner->id, $loss->changed_by);
        $this->put($url, ['stage_id' => $won->id, 'expected_stage_id' => $qualified->id])->assertRedirect();
        $this->assertDatabaseCount('crm_contacts', 0);
        $this->post(route('crm.leads.convert', $lead))->assertRedirect();
        $this->post(route('crm.leads.convert', $lead))->assertStatus(422);
        $this->put($url, ['stage_id' => $qualified->id, 'expected_stage_id' => $won->id])->assertSessionHasErrors('stage_id');
        $this->assertDatabaseCount('crm_contacts', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'crm.lead.stage_changed', 'subject_id' => $lead->id]);
    }

    public function test_used_and_initial_configuration_are_protected_and_unused_items_can_be_deleted(): void
    {
        $org = Organization::factory()->create();
        $this->actingAs($this->member($org));
        $pipeline = $this->pipeline($org);
        $lead = $org->leads()->create(['first_name' => 'Test', 'last_name' => 'Lead']);
        $initial = $lead->stage;
        $this->put(route('crm.pipeline-stages.update', [$pipeline, $initial]), $this->stageData($initial, ['active' => false]))->assertSessionHasErrors('active');
        $this->delete(route('crm.configuration.destroy', ['stage', $initial->id]))->assertSessionHasErrors('configuration');
        $qualified = $pipeline->stages->firstWhere('name', 'Qualified');
        $this->put(route('crm.leads.stage', $lead), ['stage_id' => $qualified->id, 'expected_stage_id' => $initial->id])->assertRedirect();
        $this->put(route('crm.pipeline-stages.update', [$pipeline, $qualified]), $this->stageData($qualified, ['type' => 'won']))->assertSessionHasErrors('type');
        $this->delete(route('crm.configuration.destroy', ['stage', $qualified->id]))->assertSessionHasErrors('configuration');
        $this->put(route('crm.pipeline-stages.update', [$pipeline, $qualified]), $this->stageData($qualified, ['active' => false, 'position' => 1, 'color' => '#00ff00']))->assertRedirect();
        $this->assertSame($qualified->id, $lead->fresh()->current_stage_id);
        $this->put(route('crm.leads.stage', $lead), ['stage_id' => $initial->id, 'expected_stage_id' => $qualified->id])->assertRedirect();
        $this->put(route('crm.leads.stage', $lead), ['stage_id' => $qualified->id, 'expected_stage_id' => $initial->id])->assertSessionHasErrors('stage_id');
        $this->delete(route('crm.configuration.destroy', ['stage', $qualified->id]))->assertSessionHasErrors('configuration');
        $this->post(route('crm.pipeline-stages.store', $pipeline), ['name' => 'Unused', 'position' => 20, 'type' => 'normal', 'color' => '#abcdef', 'active' => true])->assertRedirect();
        $unused = $pipeline->stages()->where('name', 'Unused')->sole();
        $this->delete(route('crm.configuration.destroy', ['stage', $unused->id]))->assertRedirect();
        $this->assertDatabaseMissing('crm_pipeline_stages', ['id' => $unused->id]);
        $this->post(route('crm.pipelines.store'), ['name' => 'Unused pipeline', 'active' => true])->assertRedirect();
        $empty = Pipeline::where('name', 'Unused pipeline')->sole();
        $this->delete(route('crm.configuration.destroy', ['pipeline', $empty->id]))->assertRedirect();
        $this->assertDatabaseMissing('crm_pipelines', ['id' => $empty->id]);
    }

    public function test_filters_counts_stale_moves_and_tenant_boundaries(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->member($org, OrganizationRole::Manager);
        $otherMember = $this->member($org, OrganizationRole::Member);
        $foreign = Organization::factory()->create();
        $pipeline = $this->pipeline($org);
        $qualified = $pipeline->stages->firstWhere('name', 'Qualified');
        $lead = $org->leads()->create(['first_name' => 'Assigned', 'last_name' => 'Lead', 'assigned_to' => $manager->id]);
        $org->leads()->create(['first_name' => 'Other', 'last_name' => 'Member', 'assigned_to' => $otherMember->id]);
        $initial = $lead->current_stage_id;
        $this->actingAs($manager)->put(route('crm.leads.stage', $lead), ['stage_id' => $qualified->id, 'expected_stage_id' => $initial])->assertRedirect();
        $this->put(route('crm.leads.stage', $lead), ['stage_id' => $initial, 'expected_stage_id' => $initial])->assertSessionHasErrors('stage_id');
        $this->put(route('crm.leads.stage', $lead), ['stage_id' => $qualified->id, 'expected_stage_id' => $qualified->id])->assertSessionHasErrors('stage_id');
        $this->assertSame(1, $lead->history()->count());
        $this->put(route('crm.leads.stage', $lead), ['stage_id' => $this->pipeline($foreign)->stages()->first()->id, 'expected_stage_id' => $qualified->id])->assertSessionHasErrors('stage_id');
        $foreignLead = $foreign->leads()->create(['first_name' => 'Foreign', 'last_name' => 'Lead']);
        $this->put(route('crm.leads.stage', $foreignLead), ['stage_id' => $qualified->id, 'expected_stage_id' => $foreignLead->current_stage_id])->assertNotFound();
        $this->get(route('crm.leads.index', ['pipeline_id' => $pipeline->id, 'stage_id' => $qualified->id, 'assignee_id' => $manager->id]))->assertInertia(fn (AssertableInertia $page) => $page->has('leads', 1)->where('leads.0.id', $lead->id)->where('canManagePipelines', false)->where('stageCounts.0.count', 0)->where('stageCounts.3.count', 1));
        $this->get(route('crm.leads.index', ['pipeline_id' => $this->pipeline($foreign)->id]))->assertSessionHasErrors('pipeline_id');
        $this->actingAs($otherMember)->put(route('crm.leads.stage', $lead), ['stage_id' => $initial, 'expected_stage_id' => $qualified->id])->assertForbidden();
    }

    public function test_migration_preserves_legacy_leads_and_links(): void
    {
        $org = Organization::factory()->create();
        $user = $this->member($org);
        $migration = require database_path('migrations/2026_09_16_000052_create_crm_pipelines.php');
        $rulesMigration = require database_path('migrations/2026_09_16_000053_add_crm_stage_entry_rules.php');
        $notificationMigration = require database_path('migrations/2026_09_20_000055_add_crm_stage_assignee_notifications.php');
        $followUpMigration = require database_path('migrations/2026_09_20_000056_add_crm_stage_follow_up_rules.php');
        $assignmentMigration = require database_path('migrations/2026_09_20_000057_add_crm_stage_assignment_rules.php');
        $routingMigration = require database_path('migrations/2026_09_20_000058_create_crm_assignment_routing_and_meta.php');
        $routingMigration->down();
        $assignmentMigration->down();
        $followUpMigration->down();
        $notificationMigration->down();
        $rulesMigration->down();
        $migration->down();
        $contact = DB::table('crm_contacts')->insertGetId(['organization_id' => $org->id, 'first_name' => 'Existing', 'last_name' => 'Contact']);
        $lead = DB::table('crm_leads')->insertGetId(['organization_id' => $org->id, 'first_name' => 'Legacy', 'last_name' => 'Lead', 'assigned_to' => $user->id, 'status' => 'negotiating', 'created_at' => now(), 'updated_at' => now()]);
        $converted = DB::table('crm_leads')->insertGetId(['organization_id' => $org->id, 'first_name' => 'Converted', 'last_name' => 'Lead', 'status' => 'converted', 'converted_at' => now(), 'converted_contact_id' => $contact]);
        DB::table('crm_activities')->insert(['organization_id' => $org->id, 'subject_type' => CrmLead::class, 'subject_id' => $lead, 'type' => 'call', 'notes' => 'Preserve me']);
        $migration->up();
        $rulesMigration->up();
        $notificationMigration->up();
        $followUpMigration->up();
        $assignmentMigration->up();
        $routingMigration->up();
        $pipeline = $this->pipeline($org);
        $this->assertDatabaseHas('crm_leads', ['id' => $lead, 'assigned_to' => $user->id, 'status' => 'negotiating', 'current_stage_id' => $pipeline->stages()->where('name', 'Legacy: negotiating')->sole()->id]);
        $this->assertDatabaseHas('crm_leads', ['id' => $converted, 'converted_contact_id' => $contact, 'current_stage_id' => $pipeline->stages()->where('type', 'won')->sole()->id]);
        $this->assertDatabaseHas('crm_activities', ['subject_id' => $lead, 'notes' => 'Preserve me']);
        $this->assertSame('negotiating', LeadStageHistory::where('lead_id', $lead)->sole()->snapshot['legacy_status']);
    }
}
