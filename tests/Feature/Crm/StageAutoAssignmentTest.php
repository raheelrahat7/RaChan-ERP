<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Actions\ManageLeadPipeline;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Notifications\Models\OrganizationNotification;
use App\Models\CrmActivity;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class StageAutoAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function test_initial_stage_rotates_new_leads_and_preserves_existing_assignees(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $first = $this->member($org, OrganizationRole::Member);
        $second = $this->member($org, OrganizationRole::Member);
        $this->actingAs($owner)->post(route('crm.assignment.quota'), ['user_id' => $first->id, 'max_active_leads' => 10])->assertRedirect();
        $this->post(route('crm.assignment.quota'), ['user_id' => $second->id, 'max_active_leads' => 10])->assertRedirect();
        $this->actingAs($first)->post(route('crm.assignment.check-in'), ['available' => true])->assertRedirect();
        $this->actingAs($second)->post(route('crm.assignment.check-in'), ['available' => true])->assertRedirect();
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $initial = $pipeline->stages()->where('is_initial', true)->sole();
        $url = route('crm.pipeline-stages.assignment-rule', [$pipeline, $initial]);
        $this->actingAs($owner)->put($url, ['member_ids' => [$second->id, $first->id]])->assertRedirect();
        $this->get(route('crm.pipelines.index'))->assertInertia(fn (AssertableInertia $page) => $page->component('crm/Pipelines')->has('members', 3)->where('pipelines.0.stages.0.assignment_member_ids', [$first->id, $second->id]));
        $manage = app(ManageLeadPipeline::class);
        $one = $manage->create($org, $owner, ['first_name' => 'One', 'last_name' => 'Lead']);
        $two = $manage->create($org, $owner, ['first_name' => 'Two', 'last_name' => 'Lead']);
        $three = $manage->create($org, $owner, ['first_name' => 'Three', 'last_name' => 'Lead']);
        $this->assertSame([$first->id, $second->id, $first->id], [$one->assigned_to, $two->assigned_to, $three->assigned_to]);
        $this->assertSame($first->id, $one->history()->sole()->snapshot['assigned_to']);
        $this->assertSame(3, DB::table('audit_logs')->where('event', 'crm.lead.auto_assigned')->count());
        $selfAssigned = $manage->create($org, $first, ['first_name' => 'Self', 'last_name' => 'Lead']);
        $this->assertSame($first->id, $selfAssigned->assigned_to);
        $this->assertSame(3, DB::table('audit_logs')->where('event', 'crm.lead.auto_assigned')->count());

        $org->users()->detach($second->id);
        $four = $manage->create($org, $owner, ['first_name' => 'Four', 'last_name' => 'Lead']);
        $this->assertSame($first->id, $four->assigned_to);
        $this->actingAs($owner)->put($url, ['member_ids' => []])->assertRedirect();
        $five = $manage->create($org, $owner, ['first_name' => 'Five', 'last_name' => 'Lead']);
        $this->assertNull($five->assigned_to);
        $this->assertDatabaseHas('audit_logs', ['event' => 'crm.stage.assignment_rule_updated', 'subject_id' => $initial->id]);
    }

    public function test_stage_entry_assigns_before_notification_and_follow_up_creation(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $this->actingAs($owner)->post(route('crm.assignment.quota'), ['user_id' => $agent->id, 'max_active_leads' => 10])->assertRedirect();
        $this->actingAs($agent)->post(route('crm.assignment.check-in'), ['available' => true])->assertRedirect();
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $initial = $pipeline->stages()->where('is_initial', true)->sole();
        $target = $pipeline->stages()->where('name', 'Qualified')->sole();
        $this->actingAs($owner)->put(route('crm.pipeline-stages.assignment-rule', [$pipeline, $target]), ['member_ids' => [$agent->id]])->assertRedirect();
        $this->put(route('crm.pipeline-stages.assignee-notification', [$pipeline, $target]), ['enabled' => true])->assertRedirect();
        $this->put(route('crm.pipeline-stages.follow-up-rule', [$pipeline, $target]), ['enabled' => true, 'due_days' => 0])->assertRedirect();
        $lead = app(ManageLeadPipeline::class)->create($org, $owner, ['first_name' => 'Stage', 'last_name' => 'Lead']);
        $this->assertNull($lead->assigned_to);
        app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $target->id, 'expected_stage_id' => $initial->id]);
        $this->assertSame($agent->id, $lead->fresh()->assigned_to);
        $history = $lead->history()->latest('id')->firstOrFail();
        $this->assertSame($agent->id, $history->snapshot['assigned_to']);
        $this->assertSame($agent->id, OrganizationNotification::where('category', 'crm_stage_entry')->sole()->user_id);
        $this->assertSame($history->id, CrmActivity::where('stage_history_id', $history->id)->sole()->stage_history_id);
        $this->actingAs($agent)->get(route('crm.leads.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('leads', 1)->has('followUps', 1));
        app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $initial->id, 'expected_stage_id' => $target->id]);
        app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $target->id, 'expected_stage_id' => $initial->id]);
        $this->assertSame($agent->id, $lead->fresh()->assigned_to);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'crm.lead.auto_assigned')->count());
    }

    public function test_assignment_rule_is_tenant_scoped_and_owner_only(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $manager = $this->member($org, OrganizationRole::Manager);
        $foreign = Organization::factory()->create();
        $foreignMember = $this->member($foreign, OrganizationRole::Member);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $stage = $pipeline->stages()->firstOrFail();
        $foreignPipeline = Pipeline::where('organization_id', $foreign->id)->sole();
        $foreignStage = $foreignPipeline->stages()->firstOrFail();
        $url = route('crm.pipeline-stages.assignment-rule', [$pipeline, $stage]);
        $this->actingAs($manager)->put($url, ['member_ids' => [$manager->id]])->assertForbidden();
        $this->actingAs($owner)->put($url, ['member_ids' => [$foreignMember->id]])->assertSessionHasErrors('member_ids');
        $this->put($url, ['member_ids' => [$manager->id, $manager->id]])->assertSessionHasErrors('member_ids.1');
        $this->put(route('crm.pipeline-stages.assignment-rule', [$foreignPipeline, $foreignStage]), ['member_ids' => [$manager->id]])->assertNotFound();
        $this->assertNull($stage->fresh()->assignment_member_ids);
    }
}
