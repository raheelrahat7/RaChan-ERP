<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Actions\ManageCrmSettings;
use App\Domain\Crm\Actions\ManageDealAutomation;
use App\Domain\Crm\Actions\ManageDealPipelines;
use App\Domain\Crm\Actions\ManageLeadPipeline;
use App\Domain\Crm\Models\DealPipeline;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Crm\Models\RecordFieldValue;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DealWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $org, OrganizationRole $role = OrganizationRole::Owner): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    private function pipeline(Organization $org, User $owner, string $name = 'Secondary'): DealPipeline
    {
        return app(ManageDealPipelines::class)->save($org, $owner, ['name' => $name, 'active' => true]);
    }

    private function createDeal(User $owner, DealPipeline $pipeline, array $extra = []): int
    {
        return $this->actingAs($owner)->postJson(route('crm.deals.store'), ['title' => 'Direct client deal', 'category' => 'secondary', 'pipeline_id' => $pipeline->id, ...$extra])->assertCreated()->json('deal.id');
    }

    public function test_organization_categories_can_be_renamed_and_archived_without_rewriting_deals(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $pipeline = $this->pipeline($org, $owner);
        $settings = app(ManageCrmSettings::class);
        $option = $settings->option($org, $owner, ['list_key' => 'deal_categories', 'code' => 'commercial', 'name' => 'Commercial', 'position' => 10, 'active' => true]);
        $id = $this->createDeal($owner, $pipeline, ['category' => 'commercial']);
        $settings->option($org, $owner, ['list_key' => 'deal_categories', 'name' => 'Commercial property', 'position' => 11, 'active' => false], $option->id);
        $this->putJson(route('crm.deals.update', $id), ['title' => 'Existing category preserved', 'category' => 'commercial', 'expected_version' => 1])->assertOk();
        $this->postJson(route('crm.deals.store'), ['title' => 'Archived', 'category' => 'commercial', 'pipeline_id' => $pipeline->id])->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->assertDatabaseHas('crm_deals', ['id' => $id, 'category' => 'commercial']);
        $other = Organization::factory()->create();
        $otherOwner = $this->member($other);
        $otherPipeline = $this->pipeline($other, $otherOwner);
        $this->actingAs($otherOwner)->postJson(route('crm.deals.store'), ['title' => 'Foreign category', 'category' => 'commercial', 'pipeline_id' => $otherPipeline->id])->assertUnprocessable()->assertJsonValidationErrors('category');
    }

    public function test_stage_custom_field_requirements_block_entry_and_clearing_existing_values(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $pipeline = $this->pipeline($org, $owner);
        $this->actingAs($owner)->postJson(route('crm.settings.fields.store'), ['entity' => 'deal', 'name' => 'Approval reference', 'key' => 'approval_reference', 'type' => 'text'])->assertOk();
        $won = $pipeline->stages()->where('type', 'won')->firstOrFail();
        app(ManageDealPipelines::class)->stage($org, $owner, $pipeline->id, ['name' => $won->name, 'type' => 'won', 'color' => $won->color, 'position' => $won->position, 'active' => true, 'required_fields' => ['custom:approval_reference']], $won->id);
        $id = $this->createDeal($owner, $pipeline);
        $this->putJson(route('crm.deals.stage', $id), ['stage_id' => $won->id, 'expected_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('custom_fields.approval_reference');
        $this->putJson(route('crm.deals.update', $id), ['expected_version' => 1, 'custom_fields' => ['approval_reference' => 'APP-123']])->assertOk();
        $this->putJson(route('crm.deals.stage', $id), ['stage_id' => $won->id, 'expected_version' => 2])->assertOk();
        $this->putJson(route('crm.deals.update', $id), ['expected_version' => 3, 'custom_fields' => ['approval_reference' => null]])->assertUnprocessable();
        $this->assertSame('APP-123', RecordFieldValue::where('record_id', $id)->where('entity', 'deal')->sole()->value);
    }

    public function test_direct_deals_have_independent_pipelines_stage_history_and_stale_checks(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $pipeline = $this->pipeline($org, $owner);
        $id = $this->createDeal($owner, $pipeline, ['amount' => '150000.50']);
        $this->assertDatabaseCount('crm_leads', 0);
        $this->assertDatabaseCount('crm_deal_stage_histories', 1);
        $won = $pipeline->stages()->where('type', 'won')->firstOrFail();
        $this->putJson(route('crm.deals.stage', $id), ['stage_id' => $won->id, 'expected_version' => 1])->assertOk()->assertJsonPath('deal.version', 2)->assertJsonPath('deal.stage.type', 'won');
        $this->putJson(route('crm.deals.update', $id), ['title' => 'Stale', 'expected_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $initial = $pipeline->stages()->where('is_initial', true)->firstOrFail();
        $this->putJson(route('crm.deals.stage', $id), ['stage_id' => $initial->id, 'expected_version' => 2])->assertUnprocessable()->assertJsonValidationErrors('notes');
        $this->putJson(route('crm.deals.stage', $id), ['stage_id' => $initial->id, 'expected_version' => 2, 'notes' => 'Reopened for review'])->assertOk()->assertJsonPath('deal.closed_at', null);
        $this->assertDatabaseCount('crm_deal_stage_histories', 3);
        $this->assertDatabaseHas('audit_logs', ['event' => 'crm.deal.stage_changed', 'subject_id' => $id]);
    }

    public function test_only_final_qualified_leads_create_one_linked_deal_and_retain_original(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $pipeline = $this->pipeline($org, $owner);
        $lead = app(ManageLeadPipeline::class)->create($org, $owner, ['first_name' => 'Qualified', 'last_name' => 'Client', 'email' => 'client@example.test', 'phone' => '555', 'notes' => 'Original notes']);
        $input = ['title' => 'Client resale', 'category' => 'resale', 'pipeline_id' => $pipeline->id];
        $this->actingAs($owner)->postJson(route('crm.leads.deal', $lead), $input)->assertUnprocessable()->assertJsonValidationErrors('lead_id');
        $won = Pipeline::where('organization_id', $org->id)->sole()->stages()->where('type', 'won')->firstOrFail();
        app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $won->id, 'expected_stage_id' => $lead->current_stage_id]);
        $before = $lead->fresh()->toArray();
        $id = $this->postJson(route('crm.leads.deal', $lead), $input)->assertOk()->assertJsonPath('deal.email', 'client@example.test')->json('deal.id');
        $this->postJson(route('crm.leads.deal', $lead), $input)->assertOk()->assertJsonPath('deal.id', $id);
        $this->assertDatabaseCount('crm_deals', 1);
        $this->assertSame($before, $lead->fresh()->toArray());
        $this->assertDatabaseCount('crm_contacts', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'crm.lead.deal_created', 'subject_id' => $lead->id]);
        $this->getJson(route('crm.deals.show', $id))->assertOk()->assertJsonPath('sourceLead.id', $lead->id);
    }

    public function test_pipeline_access_hides_other_teams_and_amounts_and_denies_mutations(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $alice = $this->member($org, OrganizationRole::Member);
        $bob = $this->member($org, OrganizationRole::Member);
        $pipeline = $this->pipeline($org, $owner);
        $aliceId = $this->createDeal($owner, $pipeline, ['assigned_to' => $alice->id, 'amount' => '50']);
        $bobId = $this->createDeal($owner, $pipeline, ['assigned_to' => $bob->id]);
        $rule = ['principal_type' => 'user', 'principal_id' => (string) $alice->id, 'permissions' => ['read' => 'own', 'add' => 'own', 'edit' => 'own', 'amount' => 'none']];
        $this->putJson(route('crm.deal-pipelines.access', $pipeline), $rule)->assertOk();
        $this->actingAs($alice)->getJson(route('crm.deals.index'))->assertOk()->assertJsonPath('deals.total', 1)->assertJsonMissingPath('deals.data.0.amount')->assertJsonPath('stageCounts.0.amount', null);
        $this->getJson(route('crm.deals.show', $bobId))->assertNotFound();
        $this->getJson(route('crm.deals.show', $aliceId))->assertOk()->assertJsonMissingPath('deal.amount');
        $this->putJson(route('crm.deals.update', $aliceId), ['expected_version' => 1, 'amount' => '1'])->assertForbidden();
        $this->putJson(route('crm.deals.update', $aliceId), ['expected_version' => 1, 'title' => 'Allowed edit'])->assertOk()->assertJsonPath('deal.title', 'Allowed edit');
        $this->putJson(route('crm.deal-pipelines.access', $pipeline), $rule)->assertForbidden();
        $this->actingAs($bob)->getJson(route('crm.deals.index'))->assertOk()->assertJsonPath('deals.total', 0);
        $this->postJson(route('crm.deals.store'), ['title' => 'Denied', 'category' => 'secondary', 'pipeline_id' => $pipeline->id])->assertForbidden();
    }

    public function test_foreign_pipelines_stages_assignees_and_leads_are_rejected(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $pipeline = $this->pipeline($org, $owner);
        $other = Organization::factory()->create();
        $foreign = $this->member($other);
        $foreignPipeline = $this->pipeline($other, $foreign);
        $id = $this->createDeal($owner, $pipeline);
        $this->postJson(route('crm.deals.store'), ['title' => 'Foreign', 'category' => 'offplan', 'pipeline_id' => $foreignPipeline->id])->assertUnprocessable();
        $this->putJson(route('crm.deals.stage', $id), ['stage_id' => $foreignPipeline->stages()->first()->id, 'expected_version' => 1])->assertUnprocessable();
        $this->putJson(route('crm.deals.update', $id), ['assigned_to' => $foreign->id, 'expected_version' => 1])->assertUnprocessable();
        $lead = app(ManageLeadPipeline::class)->create($other, $foreign, ['first_name' => 'Other', 'last_name' => 'Client']);
        $this->postJson(route('crm.leads.deal', $lead), ['title' => 'Foreign', 'category' => 'offplan', 'pipeline_id' => $pipeline->id])->assertNotFound();
        $this->actingAs($foreign)->getJson(route('crm.deals.show', $id))->assertNotFound();
        $this->getJson(route('crm.deals.index'))->assertJsonPath('deals.total', 0);
    }

    public function test_transfer_lost_reason_and_configured_entry_requirements_are_enforced(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $pipeline = $this->pipeline($org, $owner);
        $destination = $this->pipeline($org, $owner, 'Offplan');
        $id = $this->createDeal($owner, $pipeline);
        $target = $destination->stages()->where('is_initial', true)->first();
        $payload = ['pipeline_id' => $destination->id, 'stage_id' => $target->id, 'expected_version' => 1, 'confirmed' => true];
        $this->postJson(route('crm.deals.transfer', $id), [...$payload, 'confirmed' => false])->assertUnprocessable();
        $target->update(['required_fields' => ['phone']]);
        $this->postJson(route('crm.deals.transfer', $id), $payload)->assertUnprocessable()->assertJsonValidationErrors('stage_id');
        $this->putJson(route('crm.deals.update', $id), ['phone' => '555', 'expected_version' => 1])->assertOk();
        $this->postJson(route('crm.deals.transfer', $id), [...$payload, 'expected_version' => 2])->assertOk()->assertJsonPath('deal.pipeline_id', $destination->id);
        $lost = $destination->stages()->where('type', 'lost')->first();
        $this->putJson(route('crm.deals.stage', $id), ['stage_id' => $lost->id, 'expected_version' => 3])->assertUnprocessable()->assertJsonValidationErrors('lost_reason');
        $this->putJson(route('crm.deals.stage', $id), ['stage_id' => $lost->id, 'expected_version' => 3, 'lost_reason' => 'No finance'])->assertOk();
        $this->assertDatabaseHas('crm_deal_stage_histories', ['deal_id' => $id, 'pipeline_id' => $destination->id, 'from_stage_id' => $pipeline->stages()->where('is_initial', true)->first()->id]);
        $this->deleteJson(route('crm.deals.configuration.destroy', ['kind' => 'pipeline', 'id' => $pipeline->id]))->assertUnprocessable();
    }

    public function test_team_access_uses_live_membership_and_section_restrictions_preserve_role_boundaries(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $manager = $this->member($org, OrganizationRole::Manager);
        $member = $this->member($org, OrganizationRole::Member);
        $outsider = $this->member($org, OrganizationRole::Member);
        $department = DB::table('crm_departments')->insertGetId(['organization_id' => $org->id, 'name' => 'Sales', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $sub = DB::table('crm_subdepartments')->insertGetId(['organization_id' => $org->id, 'department_id' => $department, 'name' => 'Secondary', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $team = DB::table('crm_teams')->insertGetId(['organization_id' => $org->id, 'subdepartment_id' => $sub, 'name' => 'Team A', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$manager, $member] as $user) {
            DB::table('crm_team_memberships')->insert(['organization_id' => $org->id, 'team_id' => $team, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $pipeline = $this->pipeline($org, $owner);
        $id = $this->createDeal($owner, $pipeline, ['assigned_to' => $member->id]);
        $this->createDeal($owner, $pipeline, ['assigned_to' => $outsider->id]);
        $this->putJson(route('crm.deal-pipelines.access', $pipeline), ['principal_type' => 'team', 'principal_id' => (string) $team, 'permissions' => ['read' => 'team', 'edit' => 'team']])->assertOk();
        $this->actingAs($manager)->getJson(route('crm.deals.index'))->assertJsonPath('deals.total', 1);
        $this->putJson(route('crm.deals.update', $id), ['expected_version' => 1, 'title' => 'Managed by team'])->assertOk();
        DB::table('crm_team_memberships')->where('user_id', $manager->id)->delete();
        $this->getJson(route('crm.deals.index'))->assertJsonPath('deals.total', 0);
        $this->actingAs($owner)->putJson(route('crm.settings.section-access'), ['principal_type' => 'user', 'principal_id' => (string) $manager->id, 'permission' => 'inventory.view', 'enabled' => false])->assertOk();
        $this->assertFalse($manager->can('viewInventory', $org));
        $this->assertTrue($owner->can('viewInventory', $org));
        $this->putJson(route('crm.settings.section-access'), ['principal_type' => 'user', 'principal_id' => (string) $member->id, 'permission' => 'finance.manage', 'enabled' => true])->assertOk();
        $this->assertFalse($member->can('manageFinance', $org));
    }

    public function test_typed_entity_fields_are_independent_required_and_role_filtered(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $member = $this->member($org, OrganizationRole::Member);
        $pipeline = $this->pipeline($org, $owner);
        $field = ['name' => 'Property type', 'key' => 'property_type', 'type' => 'single_select', 'options' => ['Apartment', 'Villa'], 'required' => true];
        $this->actingAs($owner)->postJson(route('crm.settings.fields.store'), [...$field, 'entity' => 'lead'])->assertOk();
        $id = $this->postJson(route('crm.settings.fields.store'), [...$field, 'entity' => 'deal', 'view_roles' => ['owner'], 'edit_roles' => ['owner']])->assertOk()->json('field.id');
        $this->postJson(route('crm.deals.store'), ['title' => 'Required missing', 'category' => 'listing', 'pipeline_id' => $pipeline->id])->assertUnprocessable()->assertJsonValidationErrors('custom_fields.property_type');
        $this->assertDatabaseCount('crm_deals', 0);
        $dealId = $this->createDeal($owner, $pipeline, ['assigned_to' => $member->id, 'custom_fields' => ['property_type' => 'Villa']]);
        $this->getJson(route('crm.deals.show', $dealId))->assertJsonPath('customFields.0.value', 'Villa');
        $this->putJson(route('crm.settings.fields.update', $id), ['name' => 'Changed type', 'type' => 'text'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->actingAs($member)->getJson(route('crm.deals.show', $dealId))->assertOk()->assertJsonCount(0, 'customFields');
        $this->assertDatabaseCount('crm_custom_fields', 2);
    }

    public function test_contact_company_fields_and_selection_lists_are_tenant_scoped(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $contact = CrmContact::create(['organization_id' => $org->id, 'first_name' => 'Test', 'last_name' => 'Contact']);
        $this->actingAs($owner)->postJson(route('crm.settings.fields.store'), ['entity' => 'contact', 'name' => 'Budget', 'key' => 'budget', 'type' => 'currency'])->assertOk();
        $this->putJson(route('crm.records.fields.update', ['entity' => 'contact', 'record' => $contact->id]), ['custom_fields' => ['budget' => '500.25']])->assertOk()->assertJsonPath('fields.0.value', '500.25');
        $this->postJson(route('crm.settings.options.store'), ['list_key' => 'sources', 'name' => 'Referral', 'position' => 1, 'active' => true])->assertOk();
        $this->postJson(route('crm.settings.options.store'), ['list_key' => 'sources', 'name' => 'Referral', 'position' => 2, 'active' => true])->assertUnprocessable();
        $other = Organization::factory()->create();
        $foreign = $this->member($other);
        $this->actingAs($foreign)->getJson(route('crm.records.fields', ['entity' => 'contact', 'record' => $contact->id]))->assertNotFound();
        $this->getJson(route('crm.settings.data'))->assertJsonCount(0, 'options');
    }

    public function test_deal_activities_use_organization_time_and_reject_stale_or_closed_edits(): void
    {
        $org = Organization::factory()->create(['timezone' => 'Asia/Dubai']);
        $owner = $this->member($org);
        $pipeline = $this->pipeline($org, $owner);
        $id = $this->createDeal($owner, $pipeline);
        $activity = $this->postJson(route('crm.deals.activities.store', $id), ['type' => 'call', 'due_at' => '2026-10-07T10:00', 'notes' => 'Call client', 'expected_version' => 1])->assertOk()->assertJsonPath('version', 2)->json('activity.id');
        $this->assertDatabaseHas('crm_activities', ['id' => $activity, 'due_at' => '2026-10-07 06:00:00']);
        $this->putJson(route('crm.deals.activities.update', ['deal' => $id, 'activity' => $activity]), ['type' => 'call', 'expected_version' => 1, 'completed' => true])->assertUnprocessable();
        $this->putJson(route('crm.deals.activities.update', ['deal' => $id, 'activity' => $activity]), ['type' => 'call', 'expected_version' => 2, 'completed' => true])->assertOk();
        $won = $pipeline->stages()->where('type', 'won')->first();
        $this->putJson(route('crm.deals.stage', $id), ['stage_id' => $won->id, 'expected_version' => 3])->assertOk();
        $this->postJson(route('crm.deals.activities.store', $id), ['type' => 'task', 'expected_version' => 4])->assertUnprocessable();
    }

    public function test_deal_automation_executes_once_and_skips_stale_or_changed_rules(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $pipeline = $this->pipeline($org, $owner);
        $initial = $pipeline->stages()->where('is_initial', true)->first();
        $payload = ['name' => 'Follow up after entry', 'pipeline_id' => $pipeline->id, 'stage_id' => $initial->id, 'action' => 'create_follow_up', 'active' => true, 'delay_minutes' => 10, 'due_days' => 2, 'activity_type' => 'call'];
        $ruleId = $this->actingAs($owner)->postJson(route('crm.deals.automation.store'), $payload)->assertOk()->json('rule.id');
        $id = $this->createDeal($owner, $pipeline);
        $this->assertDatabaseCount('crm_activities', 0);
        $this->travel(10)->minutes();
        app(ManageDealAutomation::class)->due();
        app(ManageDealAutomation::class)->due();
        $this->assertDatabaseCount('crm_activities', 1);
        $this->assertDatabaseHas('crm_deal_automation_executions', ['outcome' => 'completed']);
        $stale = $this->createDeal($owner, $pipeline);
        $this->putJson(route('crm.deals.stage', $stale), ['stage_id' => $pipeline->stages()->where('type', 'won')->first()->id, 'expected_version' => 1])->assertOk();
        $this->travel(10)->minutes();
        app(ManageDealAutomation::class)->due();
        $this->assertDatabaseHas('crm_deal_automation_executions', ['outcome' => 'skipped_stale']);
        $this->createDeal($owner, $pipeline);
        $this->putJson(route('crm.deals.automation.update', $ruleId), [...$payload, 'name' => 'New settings'])->assertOk();
        $this->travel(10)->minutes();
        app(ManageDealAutomation::class)->due();
        $this->assertDatabaseHas('crm_deal_automation_executions', ['outcome' => 'skipped_changed']);
        $this->assertDatabaseCount('crm_activities', 1);
    }

    public function test_csv_export_requires_pipeline_export_grants_and_neutralizes_formulas(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $member = $this->member($org, OrganizationRole::Member);
        $pipeline = $this->pipeline($org, $owner);
        $id = $this->createDeal($owner, $pipeline, ['title' => '=HYPERLINK("x")', 'assigned_to' => $member->id, 'amount' => '750.25']);
        $this->actingAs($member)->get(route('crm.deals.export'))->assertForbidden();
        $this->actingAs($owner)->putJson(route('crm.deal-pipelines.access', $pipeline), ['principal_type' => 'user', 'principal_id' => (string) $member->id, 'permissions' => ['read' => 'own', 'export' => 'own', 'amount' => 'none']])->assertOk();
        $response = $this->actingAs($member)->get(route('crm.deals.export'))->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('750.25', $csv);
        $this->assertDatabaseHas('audit_logs', ['event' => 'crm.deals.exported']);
    }

    public function test_revoking_last_pipeline_rule_keeps_pipeline_private_and_move_permission_is_independent(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $member = $this->member($org, OrganizationRole::Member);
        $pipeline = $this->pipeline($org, $owner);
        $id = $this->createDeal($owner, $pipeline, ['assigned_to' => $member->id]);
        $ruleId = $this->putJson(route('crm.deal-pipelines.access', $pipeline), ['principal_type' => 'user', 'principal_id' => (string) $member->id, 'permissions' => ['read' => 'own', 'move' => 'own']])->assertOk()->json('rule.id');
        $this->actingAs($member)->putJson(route('crm.deals.stage', $id), ['expected_version' => 1, 'stage_id' => $pipeline->stages()->where('type', 'won')->first()->id])->assertOk();
        $this->putJson(route('crm.deals.update', $id), ['expected_version' => 2, 'title' => 'Denied edit'])->assertForbidden();
        $this->actingAs($owner)->deleteJson(route('crm.deals.configuration.destroy', ['kind' => 'access', 'id' => $ruleId]))->assertOk();
        $this->actingAs($member)->getJson(route('crm.deals.show', $id))->assertNotFound();
    }
}
