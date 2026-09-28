<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Pipeline;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class StageEntryRulesTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    private function rules(array $overrides = []): array
    {
        return [...['restrict_transitions' => false, 'allowed_from_stage_ids' => [], 'restrict_roles' => false, 'entry_roles' => [], 'required_fields' => []], ...$overrides];
    }

    public function test_source_restrictions_are_validated_scoped_audited_and_can_be_cleared(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $lead = $org->leads()->create(['first_name' => 'Test', 'last_name' => 'Lead']);
        $initial = $lead->current_stage_id;
        $target = $pipeline->stages()->where('name', 'Qualified')->sole();
        $url = route('crm.pipeline-stages.rules', [$pipeline, $target]);
        $move = route('crm.leads.stage', $lead);
        $this->actingAs($owner)->put($url, $this->rules(['restrict_transitions' => true]))->assertRedirect();
        $this->put($move, ['stage_id' => $target->id, 'expected_stage_id' => $initial])->assertSessionHasErrors('stage_id');
        $this->assertSame($initial, $lead->fresh()->current_stage_id);
        $this->assertSame(0, $lead->history()->count());
        $foreign = Pipeline::where('organization_id', Organization::factory()->create()->id)->sole();
        $this->put($url, $this->rules(['restrict_transitions' => true, 'allowed_from_stage_ids' => [$foreign->stages()->first()->id]]))->assertSessionHasErrors('allowed_from_stage_ids');
        $this->put($url, $this->rules(['restrict_transitions' => true, 'allowed_from_stage_ids' => [$target->id]]))->assertSessionHasErrors('allowed_from_stage_ids');
        $this->put(route('crm.pipeline-stages.rules', [$foreign, $target]), $this->rules())->assertNotFound();
        $this->put($url, $this->rules(['restrict_transitions' => true, 'allowed_from_stage_ids' => [$initial]]))->assertRedirect();
        $this->put($move, ['stage_id' => $target->id, 'expected_stage_id' => $initial])->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['event' => 'crm.stage.rules_updated', 'subject_id' => $target->id]);
        $this->put($url, $this->rules())->assertRedirect();
        $this->assertNull($target->fresh()->allowed_from_stage_ids);
        $this->actingAs($this->member($org, OrganizationRole::Manager))->put($url, $this->rules())->assertForbidden();
    }

    public function test_required_details_can_be_completed_and_blocked_reasons_are_shared_with_the_interface(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $lead = $org->leads()->create(['first_name' => 'Test', 'last_name' => 'Lead', 'phone' => '   ']);
        $initial = $lead->current_stage_id;
        $target = $pipeline->stages()->where('name', 'Qualified')->sole();
        $this->actingAs($owner)->put(route('crm.pipeline-stages.rules', [$pipeline, $target]), $this->rules(['required_fields' => ['phone', 'email']]))->assertRedirect();
        $this->get(route('crm.leads.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('leads.0.transitionOptions.3.stage_id', $target->id)->where('leads.0.transitionOptions.3.reasons.0', fn ($reason) => str_contains($reason, 'Phone, Email')));
        $this->put(route('crm.leads.stage', $lead), ['stage_id' => $target->id, 'expected_stage_id' => $initial])->assertSessionHasErrors('stage_id');
        $this->put(route('crm.leads.details', $lead), ['first_name' => 'Test', 'last_name' => 'Lead', 'phone' => '+971501234567', 'email' => 'lead@example.test'])->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['event' => 'crm.lead.details_updated', 'subject_id' => $lead->id]);
        $this->put(route('crm.leads.stage', $lead), ['stage_id' => $target->id, 'expected_stage_id' => $initial])->assertRedirect();
        $this->assertSame($target->id, $lead->fresh()->current_stage_id);
        $this->put(route('crm.pipeline-stages.rules', [$pipeline, $target]), $this->rules(['required_fields' => ['password']]))->assertSessionHasErrors('required_fields.0');
    }

    public function test_won_rules_apply_to_conversion_including_already_won_leads_without_partial_customer_creation(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $manager = $this->member($org, OrganizationRole::Manager);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $lead = $org->leads()->create(['first_name' => 'Test', 'last_name' => 'Lead', 'company' => 'Prospect', 'assigned_to' => $manager->id]);
        $initial = $lead->current_stage_id;
        $won = $pipeline->stages()->where('type', 'won')->sole();
        $url = route('crm.pipeline-stages.rules', [$pipeline, $won]);
        $this->actingAs($owner)->put($url, $this->rules(['restrict_roles' => true, 'entry_roles' => ['owner'], 'required_fields' => ['phone'], 'restrict_transitions' => true]))->assertRedirect();
        $this->actingAs($manager)->post(route('crm.leads.convert', $lead))->assertSessionHasErrors('stage_id');
        $this->assertDatabaseCount('crm_contacts', 0);
        $this->assertDatabaseCount('crm_accounts', 0);
        $this->assertSame($initial, $lead->fresh()->current_stage_id);
        $this->actingAs($owner)->post(route('crm.leads.convert', $lead))->assertSessionHasErrors('stage_id');
        $this->put(route('crm.leads.details', $lead), ['first_name' => 'Test', 'last_name' => 'Lead', 'company' => 'Prospect', 'phone' => '123'])->assertRedirect();
        $this->post(route('crm.leads.convert', $lead))->assertSessionHasErrors('stage_id');
        $this->assertDatabaseCount('crm_contacts', 0);
        $this->put($url, $this->rules(['restrict_roles' => true, 'entry_roles' => ['owner'], 'required_fields' => ['phone'], 'restrict_transitions' => true, 'allowed_from_stage_ids' => [$initial]]))->assertRedirect();
        $this->put(route('crm.leads.stage', $lead), ['stage_id' => $won->id, 'expected_stage_id' => $initial])->assertRedirect();
        $this->actingAs($manager)->post(route('crm.leads.convert', $lead))->assertSessionHasErrors('stage_id');
        $this->actingAs($owner)->post(route('crm.leads.convert', $lead))->assertRedirect();
        $this->assertDatabaseCount('crm_contacts', 1);
        $this->assertDatabaseCount('crm_accounts', 1);
        $this->put(route('crm.leads.details', $lead), ['first_name' => 'Changed', 'last_name' => 'Lead'])->assertSessionHasErrors('first_name');
    }

    public function test_creation_and_reopening_obey_entry_requirements_and_configuration_edits_preserve_rules(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $initial = $pipeline->stages()->where('is_initial', true)->sole();
        $lost = $pipeline->stages()->where('type', 'lost')->sole();
        $url = route('crm.pipeline-stages.rules', [$pipeline, $initial]);
        $this->actingAs($owner)->put($url, $this->rules(['required_fields' => ['phone'], 'restrict_transitions' => true, 'allowed_from_stage_ids' => []]))->assertRedirect();
        $this->post(route('crm.leads.store'), ['first_name' => 'Test', 'last_name' => 'Lead'])->assertSessionHasErrors('stage_id');
        $this->assertSame(0, $org->leads()->count());
        $this->post(route('crm.leads.store'), ['first_name' => 'Test', 'last_name' => 'Lead', 'phone' => '123'])->assertRedirect();
        $lead = $org->leads()->sole();
        $this->put(route('crm.leads.stage', $lead), ['stage_id' => $lost->id, 'expected_stage_id' => $initial->id, 'lost_reason_id' => $pipeline->reasons()->first()->id])->assertRedirect();
        $this->put(route('crm.leads.stage', $lead), ['stage_id' => $initial->id, 'expected_stage_id' => $lost->id])->assertSessionHasErrors('stage_id');
        $this->put($url, $this->rules(['required_fields' => ['phone'], 'restrict_transitions' => true, 'allowed_from_stage_ids' => [$lost->id]]))->assertRedirect();
        $this->put(route('crm.pipeline-stages.update', [$pipeline, $initial]), [...$initial->only('name', 'description', 'position', 'type', 'color', 'active'), 'name' => 'Start'])->assertRedirect();
        $this->assertSame([$lost->id], $initial->fresh()->allowed_from_stage_ids);
        $this->assertSame(['phone'], $initial->fresh()->required_fields);
        $this->put(route('crm.leads.stage', $lead), ['stage_id' => $initial->id, 'expected_stage_id' => $lost->id])->assertRedirect();
        $this->assertNull($lead->fresh()->lost_reason_id);
    }

    public function test_referenced_source_stages_cannot_be_deleted_and_lead_edits_are_tenant_scoped(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $source = $pipeline->stages()->where('name', 'Referral Leads')->sole();
        $target = $pipeline->stages()->where('name', 'Qualified')->sole();
        $this->actingAs($owner)->put(route('crm.pipeline-stages.rules', [$pipeline, $target]), $this->rules(['restrict_transitions' => true, 'allowed_from_stage_ids' => [$source->id]]))->assertRedirect();
        $this->delete(route('crm.configuration.destroy', ['stage', $source->id]))->assertSessionHasErrors('configuration');
        $foreign = Organization::factory()->create()->leads()->create(['first_name' => 'Other', 'last_name' => 'Lead']);
        $this->put(route('crm.leads.details', $foreign), ['first_name' => 'Changed', 'last_name' => 'Lead'])->assertNotFound();
        $lead = $org->leads()->create(['first_name' => 'Local', 'last_name' => 'Lead']);
        $this->actingAs($this->member($org, OrganizationRole::Viewer))->put(route('crm.leads.details', $lead), ['first_name' => 'Changed', 'last_name' => 'Lead'])->assertForbidden();
    }
}
