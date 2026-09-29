<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Events\LeadStageChanged;
use App\Domain\Crm\Models\LeadStageHistory;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\AuditLog;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AutomationAndTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function member(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function test_matching_rule_runs_once_and_timeline_records_opening_without_duplicate_views(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $pipeline = Pipeline::where('organization_id', $org->id)->firstOrFail();
        $stage = $pipeline->stages()->where('is_initial', true)->firstOrFail();
        $this->actingAs($owner)->post(route('crm.automation.store'), [
            'name' => 'Call new web leads', 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id,
            'trigger' => 'lead_created', 'condition_field' => 'source', 'condition_operator' => 'equals',
            'condition_value' => 'web', 'action' => 'create_follow_up', 'due_days' => 2,
        ])->assertRedirect();
        $this->post(route('crm.leads.store'), ['first_name' => 'Sara', 'last_name' => 'Ali', 'source' => 'web'])->assertRedirect();
        $lead = CrmLead::sole();
        $this->assertDatabaseCount('crm_automation_executions', 1);
        $this->assertDatabaseHas('crm_activities', ['subject_id' => $lead->id, 'type' => 'task', 'notes' => 'Call new web leads']);
        event(new LeadStageChanged(LeadStageHistory::where('lead_id', $lead->id)->firstOrFail()));
        $this->assertDatabaseCount('crm_automation_executions', 1);
        $this->assertDatabaseCount('crm_activities', 1);
        $this->get(route('crm.leads.show', $lead))->assertInertia(fn (Assert $page) => $page->component('crm/LeadShow')->where('lead.id', $lead->id)->has('timeline')->etc());
        $this->get(route('crm.leads.show', $lead))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'subject_type' => $lead->getMorphClass(), 'subject_id' => $lead->id, 'event' => 'crm.lead.viewed']);
        $this->assertSame(1, AuditLog::where('organization_id', $org->id)->where('event', 'crm.lead.viewed')->where('subject_id', $lead->id)->count());
    }

    public function test_unrelated_member_cannot_open_a_lead_or_see_its_timeline(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $member = $this->member($org, OrganizationRole::Member);
        $this->actingAs($owner)->post(route('crm.leads.store'), ['first_name' => 'Private', 'last_name' => 'Lead'])->assertRedirect();
        $this->actingAs($member)->get(route('crm.leads.show', CrmLead::sole()))->assertNotFound();
    }
}
