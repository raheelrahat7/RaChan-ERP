<?php

namespace Tests\Feature\Crm;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LeadResponsiblePersonTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function test_owner_can_create_a_lead_for_themselves_or_another_member_and_reassign_it(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Manager);

        $this->actingAs($owner)->post(route('crm.leads.store'), ['first_name' => 'A', 'last_name' => 'Self', 'assigned_to' => $owner->id])->assertRedirect();
        $this->post(route('crm.leads.store'), ['first_name' => 'B', 'last_name' => 'Other', 'assigned_to' => $agent->id])->assertRedirect();
        $this->assertSame($owner->id, CrmLead::where('last_name', 'Self')->value('assigned_to'));
        $lead = CrmLead::where('last_name', 'Other')->first();
        $this->assertSame($agent->id, $lead->assigned_to);

        $this->put(route('crm.leads.assignment', $lead), ['assigned_to' => $owner->id])->assertRedirect();
        $this->assertSame($owner->id, $lead->fresh()->assigned_to);
    }

    public function test_restricted_member_is_assigned_their_own_leads_and_cannot_pick_someone_else(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $manager = $this->member($org, OrganizationRole::Manager);

        $this->actingAs($manager)->post(route('crm.leads.store'), ['first_name' => 'C', 'last_name' => 'Mine'])->assertRedirect();
        $this->assertSame($manager->id, CrmLead::where('last_name', 'Mine')->value('assigned_to'));
        $this->post(route('crm.leads.store'), ['first_name' => 'D', 'last_name' => 'Nope', 'assigned_to' => $owner->id])->assertSessionHasErrors('assignee_id');
        $this->assertDatabaseMissing('crm_leads', ['last_name' => 'Nope']);
    }

    public function test_lead_page_lists_the_members_it_can_be_assigned_to(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $this->member($org, OrganizationRole::Manager);
        $this->actingAs($owner)->post(route('crm.leads.store'), ['first_name' => 'E', 'last_name' => 'Page', 'assigned_to' => $owner->id])->assertRedirect();

        $this->get(route('crm.leads.show', CrmLead::sole()))->assertInertia(fn (Assert $page) => $page->has('members', 2)->where('lead.assigned_to', $owner->id)->etc());
    }
}
