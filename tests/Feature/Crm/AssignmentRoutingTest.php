<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Actions\ManageLeadPipeline;
use App\Domain\Crm\Models\AssignmentHold;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Notifications\Models\OrganizationNotification;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssignmentRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function test_meta_form_route_takes_priority_and_quota_holds_then_retries_in_arrival_order(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $admin = $this->member($org, OrganizationRole::Administrator);
        $agent = $this->member($org, OrganizationRole::Member);
        $backup = $this->member($org, OrganizationRole::Member);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $initial = $pipeline->stages()->where('is_initial', true)->sole();
        $this->actingAs($owner)->put(route('crm.pipeline-stages.assignment-rule', [$pipeline, $initial]), ['member_ids' => [$backup->id]])->assertRedirect();
        $this->post(route('crm.assignment.quota'), ['user_id' => $agent->id, 'max_active_leads' => 1])->assertRedirect();
        $this->post(route('crm.assignment.quota'), ['user_id' => $backup->id, 'max_active_leads' => 10])->assertRedirect();
        $this->actingAs($agent)->post(route('crm.assignment.check-in'), ['available' => true])->assertRedirect();
        $this->actingAs($backup)->post(route('crm.assignment.check-in'), ['available' => true])->assertRedirect();
        $this->actingAs($owner)->post(route('crm.assignment.routes.store'), ['match_type' => 'meta_form_id', 'match_value' => '12345', 'target_type' => 'members', 'member_ids' => [$agent->id], 'active' => true])->assertRedirect();
        $this->post(route('crm.assignment.routes.store'), ['match_type' => 'campaign', 'match_value' => 'summer', 'target_type' => 'members', 'member_ids' => [$backup->id], 'active' => true])->assertRedirect();
        $manage = app(ManageLeadPipeline::class);
        $one = $manage->create($org, $owner, ['first_name' => 'One', 'last_name' => 'Lead', 'meta_form_id' => '12345', 'campaign_name' => 'Summer']);
        $two = $manage->create($org, $owner, ['first_name' => 'Two', 'last_name' => 'Lead', 'meta_form_id' => '12345', 'campaign_name' => 'Summer']);
        $three = $manage->create($org, $owner, ['first_name' => 'Three', 'last_name' => 'Lead', 'meta_form_id' => '12345', 'campaign_name' => 'Summer']);
        $this->assertSame($agent->id, $one->assigned_to);
        $this->assertNull($two->assigned_to);
        $this->assertNull($three->assigned_to);
        $this->assertDatabaseCount('crm_assignment_holds', 2);
        $this->assertSame(2, OrganizationNotification::where('category', 'crm_assignment_hold')->where('user_id', $owner->id)->count());
        $this->assertSame(2, OrganizationNotification::where('category', 'crm_assignment_hold')->where('user_id', $admin->id)->count());
        $this->assertSame(0, OrganizationNotification::where('category', 'crm_assignment_hold')->where('user_id', $agent->id)->count());
        $this->actingAs($owner)->post(route('crm.assignment.quota'), ['user_id' => $agent->id, 'max_active_leads' => 2])->assertRedirect();
        $this->assertSame($agent->id, $two->fresh()->assigned_to);
        $this->assertNull($three->fresh()->assigned_to);
        $this->post(route('crm.assignment.quota'), ['user_id' => $agent->id, 'max_active_leads' => 3])->assertRedirect();
        $this->assertSame($agent->id, $three->fresh()->assigned_to);
        $this->assertSame(0, AssignmentHold::whereNull('resolved_at')->count());
        $campaign = $manage->create($org, $owner, ['first_name' => 'Campaign', 'last_name' => 'Lead', 'campaign_name' => 'Summer']);
        $this->assertSame($backup->id, $campaign->assigned_to);
        $fallback = $manage->create($org, $owner, ['first_name' => 'Stage', 'last_name' => 'Lead']);
        $this->assertSame($backup->id, $fallback->assigned_to);
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'crm.lead.assignment_held')->count());
    }

    public function test_daily_check_in_and_tenant_scoped_route_configuration(): void
    {
        $org = Organization::factory()->create(['timezone' => 'Asia/Dubai']);
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $other = Organization::factory()->create();
        $outsider = $this->member($other, OrganizationRole::Member);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $initial = $pipeline->stages()->where('is_initial', true)->sole();
        $this->actingAs($owner)->put(route('crm.pipeline-stages.assignment-rule', [$pipeline, $initial]), ['member_ids' => [$agent->id]])->assertRedirect();
        $this->post(route('crm.assignment.quota'), ['user_id' => $agent->id, 'max_active_leads' => 2])->assertRedirect();
        $held = app(ManageLeadPipeline::class)->create($org, $owner, ['first_name' => 'Held', 'last_name' => 'Lead']);
        $this->assertNull($held->assigned_to);
        $this->actingAs($agent)->post(route('crm.assignment.check-in'), ['available' => true])->assertRedirect();
        $this->assertSame($agent->id, $held->fresh()->assigned_to);
        $this->post(route('crm.assignment.check-in'), ['available' => false])->assertRedirect();
        $this->actingAs($owner)->post(route('crm.assignment.routes.store'), ['match_type' => 'project', 'match_value' => 'Palm', 'target_type' => 'members', 'member_ids' => [$outsider->id], 'active' => true])->assertSessionHasErrors('member_ids');
        $this->post(route('crm.assignment.quota'), ['user_id' => $outsider->id, 'max_active_leads' => 10])->assertSessionHasErrors('user_id');
        $this->actingAs($agent)->post(route('crm.assignment.routes.store'), ['match_type' => 'project', 'match_value' => 'Palm', 'target_type' => 'members', 'member_ids' => [$agent->id], 'active' => true])->assertForbidden();
    }

    public function test_project_route_can_target_a_department_and_won_leads_do_not_use_quota(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $department = DB::table('crm_departments')->insertGetId(['organization_id' => $org->id, 'name' => 'Sales', 'active' => true]);
        $sub = DB::table('crm_subdepartments')->insertGetId(['organization_id' => $org->id, 'department_id' => $department, 'name' => 'Direct', 'active' => true]);
        $team = DB::table('crm_teams')->insertGetId(['organization_id' => $org->id, 'subdepartment_id' => $sub, 'name' => 'North', 'active' => true]);
        DB::table('crm_team_memberships')->insert(['organization_id' => $org->id, 'user_id' => $agent->id, 'team_id' => $team]);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $won = $pipeline->stages()->where('type', 'won')->firstOrFail();
        $old = $org->leads()->create(['first_name' => 'Old', 'last_name' => 'Won', 'assigned_to' => $agent->id]);
        $old->update(['current_stage_id' => $won->id]);
        $this->actingAs($owner)->post(route('crm.assignment.quota'), ['user_id' => $agent->id, 'max_active_leads' => 1])->assertRedirect();
        $this->actingAs($agent)->post(route('crm.assignment.check-in'), ['available' => true])->assertRedirect();
        $this->actingAs($owner)->post(route('crm.assignment.routes.store'), ['match_type' => 'project', 'match_value' => 'Palm Residences', 'target_type' => 'department', 'target_id' => $department, 'member_ids' => [], 'active' => true])->assertRedirect();
        $lead = app(ManageLeadPipeline::class)->create($org, $owner, ['first_name' => 'New', 'last_name' => 'Lead', 'project_name' => 'PALM RESIDENCES']);
        $this->assertSame($agent->id, $lead->assigned_to);
        $held = app(ManageLeadPipeline::class)->create($org, $owner, ['first_name' => 'Next', 'last_name' => 'Lead', 'project_name' => 'Palm Residences']);
        $this->assertNull($held->assigned_to);
        $this->assertSame('quota', AssignmentHold::where('lead_id', $held->id)->sole()->reason);
    }
}
