<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Actions\ManageLeadPipeline;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CrossPipelineTransferTest extends TestCase
{
    use RefreshDatabase;

    private function user(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function test_confirmed_transfer_preserves_history_and_past_pipeline_reports(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 1)->startOfDay());
        $org = Organization::factory()->create();
        $owner = $this->user($org, OrganizationRole::Owner);
        $source = Pipeline::where('organization_id', $org->id)->sole();
        $destination = Pipeline::create(['organization_id' => $org->id, 'name' => 'Commercial']);
        $target = $destination->stages()->create(['name' => 'Review', 'position' => 1, 'is_initial' => true]);
        $lead = app(ManageLeadPipeline::class)->create($org, $owner, ['first_name' => 'Transfer', 'last_name' => 'Lead']);
        $initial = $lead->current_stage_id;
        $this->travelTo(now()->setDate(2026, 9, 3)->startOfDay());
        $payload = ['pipeline_id' => $destination->id, 'stage_id' => $target->id, 'expected_pipeline_id' => $source->id, 'expected_stage_id' => $initial, 'confirmed' => true, 'notes' => 'Moved to commercial'];
        $this->actingAs($owner)->post(route('crm.leads.transfer', $lead), $payload)->assertRedirect();
        $this->assertSame($destination->id, $lead->fresh()->pipeline_id);
        $this->assertSame($target->id, $lead->fresh()->current_stage_id);
        $this->assertNull($lead->fresh()->converted_at);
        $this->assertDatabaseCount('crm_contacts', 0);
        $this->assertSame(2, $lead->history()->count());
        $history = $lead->history()->latest('id')->first();
        $this->assertSame($source->name, $history->snapshot['from_pipeline']);
        $this->assertSame($destination->name, $history->snapshot['pipeline']);
        $this->assertSame($initial, $history->from_stage_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'crm.lead.transferred', 'subject_id' => $lead->id]);
        $this->post(route('crm.leads.transfer', $lead), $payload)->assertSessionHasErrors('pipeline_id');
        $this->assertSame(2, $lead->history()->count());
        $this->get(route('crm.leads.index', ['pipeline_id' => $destination->id]))->assertInertia(fn (AssertableInertia $page) => $page->has('leads', 1)->where('leads.0.id', $lead->id));
        $this->travelTo(now()->setDate(2026, 9, 5)->startOfDay());
        $this->get(route('crm.pipeline-report', ['pipeline_id' => $source->id, 'from_date' => '2026-09-01', 'to_date' => '2026-09-02']))->assertInertia(fn (AssertableInertia $page) => $page->where('summary.total', 1));
        $this->get(route('crm.pipeline-report', ['pipeline_id' => $destination->id, 'from_date' => '2026-09-01', 'to_date' => '2026-09-02']))->assertInertia(fn (AssertableInertia $page) => $page->where('summary.total', 0));
        $this->get(route('crm.pipeline-report', ['pipeline_id' => $destination->id, 'from_date' => '2026-09-01', 'to_date' => '2026-09-04']))->assertInertia(fn (AssertableInertia $page) => $page->where('summary.total', 1)->where('stageTimes.0.stage', 'Review'));
    }

    public function test_transfer_checks_confirmation_tenant_destination_rules_and_lost_reopening(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->user($org, OrganizationRole::Owner);
        $manager = $this->user($org, OrganizationRole::Manager);
        $source = Pipeline::where('organization_id', $org->id)->sole();
        $destination = Pipeline::create(['organization_id' => $org->id, 'name' => 'New pipeline']);
        $target = $destination->stages()->create(['name' => 'Initial', 'position' => 1, 'is_initial' => true, 'required_fields' => ['phone']]);
        $lost = $destination->stages()->create(['name' => 'Lost', 'position' => 2, 'type' => 'lost']);
        $reason = $destination->reasons()->create(['name' => 'No budget', 'position' => 1]);
        $lead = app(ManageLeadPipeline::class)->create($org, $owner, ['first_name' => 'Open', 'last_name' => 'Lead']);
        $payload = ['pipeline_id' => $destination->id, 'stage_id' => $target->id, 'expected_pipeline_id' => $source->id, 'expected_stage_id' => $lead->current_stage_id, 'confirmed' => true];
        $this->actingAs($owner)->post(route('crm.leads.transfer', $lead), [...$payload, 'confirmed' => false])->assertSessionHasErrors('confirmed');
        $this->post(route('crm.leads.transfer', $lead), $payload)->assertSessionHasErrors('stage_id');
        $this->post(route('crm.leads.transfer', $lead), [...$payload, 'stage_id' => $lost->id])->assertSessionHasErrors('lost_reason_id');
        $this->post(route('crm.leads.transfer', $lead), [...$payload, 'pipeline_id' => $source->id])->assertSessionHasErrors('pipeline_id');
        $this->post(route('crm.leads.transfer', $lead), [...$payload, 'expected_stage_id' => -1])->assertSessionHasErrors('pipeline_id');
        $foreign = Organization::factory()->create();
        $foreignPipeline = Pipeline::where('organization_id', $foreign->id)->sole();
        $this->post(route('crm.leads.transfer', $lead), [...$payload, 'pipeline_id' => $foreignPipeline->id])->assertSessionHasErrors('pipeline_id');
        $this->actingAs($manager)->post(route('crm.leads.transfer', $lead), [...$payload, 'stage_id' => $lost->id, 'lost_reason_id' => $reason->id])->assertNotFound();
        $this->assertSame(1, $lead->history()->count());
        $this->actingAs($owner)->post(route('crm.leads.transfer', $lead), [...$payload, 'stage_id' => $lost->id, 'lost_reason_id' => $reason->id])->assertRedirect();
        $this->assertSame($reason->id, $lead->fresh()->lost_reason_id);
        $this->post(route('crm.leads.transfer', $lead), ['pipeline_id' => $source->id, 'stage_id' => $source->stages()->where('type', 'won')->first()->id, 'expected_pipeline_id' => $destination->id, 'expected_stage_id' => $lost->id, 'confirmed' => true])->assertSessionHasErrors('stage_id');
        $this->post(route('crm.leads.transfer', $lead), ['pipeline_id' => $source->id, 'stage_id' => $source->stages()->where('type', 'normal')->first()->id, 'expected_pipeline_id' => $destination->id, 'expected_stage_id' => $lost->id, 'confirmed' => true])->assertRedirect();
        $this->assertNull($lead->fresh()->lost_reason_id);
        $this->assertSame(3, $lead->history()->count());
        $won = $destination->stages()->create(['name' => 'Won', 'position' => 3, 'type' => 'won']);
        $this->post(route('crm.leads.transfer', $lead), ['pipeline_id' => $destination->id, 'stage_id' => $won->id, 'expected_pipeline_id' => $source->id, 'expected_stage_id' => $lead->fresh()->current_stage_id, 'confirmed' => true])->assertRedirect();
        $this->assertNull($lead->fresh()->converted_at);
        $this->assertDatabaseCount('crm_contacts', 0);
        $this->post(route('crm.leads.convert', $lead))->assertRedirect();
        $this->post(route('crm.leads.transfer', $lead), ['pipeline_id' => $source->id, 'stage_id' => $source->stages()->where('type', 'normal')->first()->id, 'expected_pipeline_id' => $destination->id, 'expected_stage_id' => $won->id, 'confirmed' => true])->assertSessionHasErrors('pipeline_id');
    }
}
