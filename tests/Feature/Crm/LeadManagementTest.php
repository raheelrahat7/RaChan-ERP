<?php

namespace Tests\Feature\Crm;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class LeadManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_and_scheduled_follow_up_are_scoped_and_audited(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $foreign = User::factory()->create();
        $lead = $org->leads()->create(['first_name' => 'Test', 'last_name' => 'Lead']);
        $this->actingAs($owner)->put(route('crm.leads.assignment', $lead), ['assigned_to' => $manager->id])->assertRedirect();
        $this->actingAs($manager)->put(route('crm.leads.assignment', $lead), ['assigned_to' => $foreign->id])->assertStatus(422);
        $this->put(route('crm.leads.assignment', $lead), ['assigned_to' => $manager->id])->assertRedirect();
        $this->assertSame($manager->id, $lead->fresh()->assigned_to);
        $this->post(route('crm.activities.store'), ['lead_id' => $lead->id, 'type' => 'call', 'due_at' => now()->subHour()->toDateTimeString()])->assertRedirect();
        $activity = $lead->activities()->sole();
        $this->get(route('crm.leads.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('followUps', 1)->where('followUps.0.is_overdue', true));
        $this->post(route('crm.activities.complete', $activity))->assertRedirect();
        $this->assertNotNull($activity->fresh()->completed_at);
        $this->post(route('crm.activities.complete', $activity))->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['event' => 'crm.follow_up.completed', 'subject_id' => $activity->id]);
        $this->put(route('crm.leads.assignment', $lead), ['assigned_to' => null])->assertForbidden();
        $this->actingAs($owner)->put(route('crm.leads.assignment', $lead), ['assigned_to' => null])->assertRedirect();
        $this->assertNull($lead->fresh()->assigned_to);
        $viewer = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($viewer, ['role' => OrganizationRole::Viewer->value]);
        $this->actingAs($viewer)->put(route('crm.leads.assignment', $lead), ['assigned_to' => $manager->id])->assertForbidden();
    }

    public function test_a_manager_can_create_and_convert_a_tenant_lead(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);

        $this->actingAs($manager)->post(route('crm.leads.store'), [
            'first_name' => 'Avery',
            'last_name' => 'Jordan',
            'email' => 'avery@example.com',
            'company' => 'Northstar Properties',
            'source' => 'Referral',
        ])->assertRedirect();

        $leadId = $organization->leads()->sole()->id;
        $this->actingAs($manager)->post(route('crm.leads.convert', $leadId))->assertRedirect();

        $this->assertDatabaseHas('crm_contacts', ['organization_id' => $organization->id, 'email' => 'avery@example.com']);
        $this->assertDatabaseHas('crm_accounts', ['organization_id' => $organization->id, 'name' => 'Northstar Properties']);
        $this->assertDatabaseHas('crm_leads', ['id' => $leadId, 'status' => 'converted']);
        $this->assertSame('won', $organization->leads()->sole()->stage->type);
        $this->assertSame(2, $organization->leads()->sole()->history()->count());
    }

    public function test_a_manager_cannot_convert_another_tenants_lead(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $lead = $otherOrganization->leads()->create(['first_name' => 'Other', 'last_name' => 'Lead']);

        $this->actingAs($manager)->post(route('crm.leads.convert', $lead))->assertNotFound();
        $this->put(route('crm.leads.assignment', $lead), ['assigned_to' => $manager->id])->assertNotFound();
    }

    public function test_a_manager_can_create_a_contact_and_log_an_activity_for_its_tenant_lead(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $lead = $organization->leads()->create(['first_name' => 'Kai', 'last_name' => 'Morgan', 'assigned_to' => $manager->id]);

        $this->actingAs($manager)->post(route('crm.contacts.store'), [
            'first_name' => 'Robin',
            'last_name' => 'Lee',
            'email' => 'robin@example.com',
            'company' => 'Summit Group',
        ])->assertRedirect();
        $this->actingAs($manager)->post(route('crm.activities.store'), [
            'lead_id' => $lead->id,
            'type' => 'call',
            'notes' => 'Discussed viewing options.',
        ])->assertRedirect();

        $this->assertDatabaseHas('crm_contacts', ['organization_id' => $organization->id, 'email' => 'robin@example.com']);
        $this->assertDatabaseHas('crm_activities', ['organization_id' => $organization->id, 'subject_id' => $lead->id, 'type' => 'call']);
    }
}
