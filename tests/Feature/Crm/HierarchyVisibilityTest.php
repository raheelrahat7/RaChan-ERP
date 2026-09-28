<?php

namespace Tests\Feature\Crm;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class HierarchyVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function test_department_and_user_grants_scope_leads_without_granting_write_permission(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $manager = $this->member($org, OrganizationRole::Manager);
        $supervisor = $this->member($org, OrganizationRole::Member);
        $agent = $this->member($org, OrganizationRole::Member);
        $other = $this->member($org, OrganizationRole::Member);
        $this->actingAs($owner)->post(route('crm.hierarchy.create', 'department'), ['name' => 'Sales'])->assertRedirect();
        $this->get(route('crm.hierarchy.index'))->assertInertia(fn (AssertableInertia $page) => $page->component('crm/Hierarchy')->has('departments', 1)->has('members', 5));
        $department = DB::table('crm_departments')->where('organization_id', $org->id)->sole();
        $this->post(route('crm.hierarchy.create', 'subdepartment'), ['name' => 'Residential', 'department_id' => $department->id])->assertRedirect();
        $subdepartment = DB::table('crm_subdepartments')->where('organization_id', $org->id)->sole();
        $this->post(route('crm.hierarchy.create', 'team'), ['name' => 'North', 'subdepartment_id' => $subdepartment->id])->assertRedirect();
        $team = DB::table('crm_teams')->where('organization_id', $org->id)->sole();
        $this->put(route('crm.hierarchy.place'), ['user_id' => $agent->id, 'team_id' => $team->id])->assertRedirect();
        $this->post(route('crm.hierarchy.grant'), ['user_id' => $manager->id, 'scope_type' => 'department', 'scope_id' => $department->id])->assertRedirect();
        $this->post(route('crm.hierarchy.grant'), ['user_id' => $supervisor->id, 'scope_type' => 'subdepartment', 'scope_id' => $subdepartment->id])->assertRedirect();
        $mine = $org->leads()->create(['first_name' => 'Manager', 'last_name' => 'Own', 'assigned_to' => $manager->id]);
        $scoped = $org->leads()->create(['first_name' => 'Agent', 'last_name' => 'Scoped', 'assigned_to' => $agent->id]);
        $outside = $org->leads()->create(['first_name' => 'Outside', 'last_name' => 'Lead', 'assigned_to' => $other->id]);
        $org->leads()->create(['first_name' => 'Unassigned', 'last_name' => 'Lead']);

        $this->actingAs($manager)->get(route('crm.leads.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('leads', 2)->has('members', 2));
        $this->get(route('crm.pipeline-report'))->assertInertia(fn (AssertableInertia $page) => $page->where('summary.total', 2));
        $this->get(route('crm.leads.index', ['assignee_id' => $other->id]))->assertSessionHasErrors('assignee_id');
        $this->post(route('crm.leads.convert', $outside))->assertNotFound();
        $this->post(route('crm.leads.convert', $scoped))->assertRedirect();
        $this->actingAs($supervisor)->get(route('crm.leads.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('leads', 1)->where('leads.0.id', $scoped->id));
        $this->post(route('crm.leads.convert', $mine))->assertForbidden();
        $this->post(route('crm.hierarchy.grant'), ['user_id' => $supervisor->id, 'scope_type' => 'organization'])->assertForbidden();
        $this->actingAs($owner)->get(route('crm.leads.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('leads', 4));
        $this->post(route('crm.hierarchy.grant'), ['user_id' => $supervisor->id, 'scope_type' => 'organization'])->assertRedirect();
        $this->actingAs($supervisor)->get(route('crm.leads.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('leads', 4));
        $grant = DB::table('crm_visibility_grants')->where('user_id', $supervisor->id)->where('scope_type', 'organization')->sole();
        $this->actingAs($owner)->delete(route('crm.hierarchy.revoke', $grant->id))->assertRedirect();
        $this->actingAs($supervisor)->get(route('crm.leads.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('leads', 1));
        $this->actingAs($owner)->put(route('crm.hierarchy.active', ['kind' => 'team', 'id' => $team->id]), ['active' => false])->assertRedirect();
        $this->put(route('crm.hierarchy.place'), ['user_id' => $other->id, 'team_id' => $team->id])->assertSessionHasErrors('team_id');
        $this->actingAs($supervisor)->get(route('crm.leads.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('leads', 1));
        $this->actingAs($owner)->delete(route('organization.members.destroy', $agent))->assertRedirect();
        $this->assertDatabaseMissing('crm_team_memberships', ['organization_id' => $org->id, 'user_id' => $agent->id]);
        $this->actingAs($manager)->get(route('crm.leads.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('leads', 1));
    }

    public function test_foreign_hierarchy_ids_and_self_service_grants_are_rejected(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $manager = $this->member($org, OrganizationRole::Manager);
        $foreign = Organization::factory()->create();
        $foreignMember = $this->member($foreign, OrganizationRole::Member);
        $foreignOwner = $this->member($foreign, OrganizationRole::Owner);
        $this->actingAs($owner)->post(route('crm.hierarchy.create', 'department'), ['name' => 'Home'])->assertRedirect();
        $this->actingAs($foreignOwner)->post(route('crm.hierarchy.create', 'department'), ['name' => 'Foreign'])->assertRedirect();
        $foreignDepartment = DB::table('crm_departments')->where('organization_id', $foreign->id)->sole();
        $this->actingAs($owner)->post(route('crm.hierarchy.create', 'subdepartment'), ['name' => 'Wrong', 'department_id' => $foreignDepartment->id])->assertSessionHasErrors('department_id');
        $this->post(route('crm.hierarchy.grant'), ['user_id' => $manager->id, 'scope_type' => 'department', 'scope_id' => $foreignDepartment->id])->assertSessionHasErrors('scope_id');
        $this->post(route('crm.hierarchy.grant'), ['user_id' => $foreignMember->id, 'scope_type' => 'organization'])->assertSessionHasErrors('user_id');
        $this->actingAs($manager)->get(route('crm.hierarchy.index'))->assertForbidden();
        $this->post(route('crm.hierarchy.grant'), ['user_id' => $manager->id, 'scope_type' => 'organization'])->assertForbidden();
        $this->put(route('organization.members.update', $manager), ['role' => 'administrator'])->assertForbidden();
    }

    public function test_member_can_edit_only_visible_leads_when_owner_grants_crm_editing(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $other = $this->member($org, OrganizationRole::Member);
        $foreign = Organization::factory()->create();
        $foreignMember = $this->member($foreign, OrganizationRole::Member);
        $mine = $org->leads()->create(['first_name' => 'My', 'last_name' => 'Lead', 'assigned_to' => $agent->id]);
        $outside = $org->leads()->create(['first_name' => 'Other', 'last_name' => 'Lead', 'assigned_to' => $other->id]);
        $this->actingAs($agent)->post(route('crm.activities.store'), ['lead_id' => $mine->id, 'type' => 'note'])->assertForbidden();
        $this->actingAs($owner)->put(route('crm.hierarchy.edit-grant'), ['user_id' => $agent->id, 'allowed' => true])->assertRedirect();
        $this->assertDatabaseHas('crm_edit_grants', ['organization_id' => $org->id, 'user_id' => $agent->id]);
        $this->actingAs($agent)->post(route('crm.activities.store'), ['lead_id' => $mine->id, 'type' => 'note'])->assertRedirect();
        $this->post(route('crm.leads.store'), ['first_name' => 'New', 'last_name' => 'Mine'])->assertRedirect();
        $this->assertDatabaseHas('crm_leads', ['organization_id' => $org->id, 'first_name' => 'New', 'assigned_to' => $agent->id]);
        $this->post(route('crm.activities.store'), ['lead_id' => $outside->id, 'type' => 'note'])->assertNotFound();
        $this->get(route('crm.hierarchy.index'))->assertForbidden();
        $this->get(route('crm.pipelines.index'))->assertForbidden();
        $this->put(route('crm.hierarchy.edit-grant'), ['user_id' => $other->id, 'allowed' => true])->assertForbidden();
        $this->actingAs($owner)->put(route('crm.hierarchy.edit-grant'), ['user_id' => $foreignMember->id, 'allowed' => true])->assertSessionHasErrors('user_id');
        $this->put(route('crm.hierarchy.edit-grant'), ['user_id' => $agent->id, 'allowed' => false])->assertRedirect();
        $this->actingAs($agent)->post(route('crm.activities.store'), ['lead_id' => $mine->id, 'type' => 'note'])->assertForbidden();
        $this->actingAs($owner)->put(route('crm.hierarchy.edit-grant'), ['user_id' => $agent->id, 'allowed' => true])->assertRedirect();
        $this->delete(route('organization.members.destroy', $agent))->assertRedirect();
        $this->assertDatabaseMissing('crm_edit_grants', ['organization_id' => $org->id, 'user_id' => $agent->id]);
    }
}
