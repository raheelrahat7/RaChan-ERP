<?php

namespace Tests\Feature\Crm;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class LeadVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_and_viewer_only_see_their_assigned_leads_across_views_and_export(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->member($org, OrganizationRole::Manager);
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $viewer = $this->member($org, OrganizationRole::Viewer);
        $mine = $org->leads()->create(['first_name' => 'Mine', 'last_name' => 'Lead', 'assigned_to' => $agent->id]);
        $other = $org->leads()->create(['first_name' => 'Other', 'last_name' => 'Lead', 'assigned_to' => $viewer->id]);
        $org->leads()->create(['first_name' => 'Unassigned', 'last_name' => 'Lead']);
        $mine->activities()->create(['organization_id' => $org->id, 'created_by' => $manager->id, 'type' => 'task', 'due_at' => now()->subHour()]);
        $other->activities()->create(['organization_id' => $org->id, 'created_by' => $manager->id, 'type' => 'task', 'due_at' => now()->subHour()]);

        $this->actingAs($agent)->get(route('crm.leads.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('leads', 1)->where('leads.0.first_name', 'Mine')->where('filters.assignee_id', $agent->id)
            ->has('members', 1)->has('followUps', 1)->has('activities', 1));
        $this->get(route('crm.leads.index', ['assignee_id' => $viewer->id]))->assertSessionHasErrors('assignee_id');
        $this->get(route('crm.pipeline-report'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.total', 1)->where('filters.assignee_id', $agent->id)->has('members', 1));
        $this->get(route('crm.pipeline-report', ['assignee_id' => $viewer->id]))->assertSessionHasErrors('assignee_id');
        $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('metrics.activeLeads', 1)->where('alerts.0.category', 'overdue_crm_follow_ups')->where('alerts.0.count', 1));
        $csv = $this->get(route('reports.export', 'leads'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Mine', $csv);
        $this->assertStringNotContainsString('Other', $csv);
        $this->assertStringNotContainsString('Unassigned', $csv);

        $this->actingAs($viewer)->get(route('crm.leads.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('leads', 1)->where('leads.0.first_name', 'Other')->where('filters.assignee_id', $viewer->id));
        $this->actingAs($manager)->get(route('crm.leads.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('leads', 0)->has('members', 1));
        $this->actingAs($owner)->get(route('crm.leads.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('leads', 3)->has('members', 4));
    }

    private function member(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }
}
