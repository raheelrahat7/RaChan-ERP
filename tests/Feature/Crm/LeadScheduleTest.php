<?php

namespace Tests\Feature\Crm;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function test_lead_tasks_are_scoped_versioned_and_completable(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $assignee = $this->member($org, OrganizationRole::Member);
        $lead = CrmLead::create(['organization_id' => $org->id, 'assigned_to' => $assignee->id, 'first_name' => 'Task']);
        $other = CrmLead::create(['organization_id' => $org->id, 'assigned_to' => $assignee->id, 'first_name' => 'Other']);
        $base = "/crm/leads/{$lead->id}/tasks";
        $this->actingAs($owner);
        $id = $this->postJson($base, ['title' => 'Call buyer', 'assigned_to' => $assignee->id, 'due_at' => '2027-01-02T12:00:00Z'])->assertCreated()->assertJsonPath('task.version', 1)->assertJsonPath('task.permissions.edit', true)->json('task.id');
        $this->getJson($base)->assertOk()->assertJsonPath('tasks.total', 1)->assertJsonPath('tasks.data.0.title', 'Call buyer');
        $this->putJson("$base/$id", ['title' => 'Call again'])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson("$base/$id", ['expected_version' => 0, 'title' => 'Call again'])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson("$base/$id", ['expected_version' => 1, 'title' => 'Call again'])->assertOk()->assertJsonPath('task.version', 2);
        $this->postJson("/crm/leads/{$other->id}/tasks/$id/complete", ['expected_version' => 2])->assertNotFound();
        $this->actingAs($assignee)->getJson($base)->assertOk()->assertJsonPath('tasks.data.0.permissions.edit', false)->assertJsonPath('tasks.data.0.permissions.complete', true);
        $this->postJson("$base/$id/complete", ['expected_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->postJson("$base/$id/complete", ['expected_version' => 2])->assertOk()->assertJsonPath('task.status', 'completed')->assertJsonPath('task.version', 3);
        $this->assertDatabaseHas('audit_logs', ['event' => 'tasks.completed']);
    }

    public function test_lead_meetings_support_edit_outcome_cancel_and_tenant_isolation(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $lead = CrmLead::create(['organization_id' => $org->id, 'assigned_to' => $owner->id, 'first_name' => 'Meeting']);
        $foreignOrg = Organization::factory()->create();
        $foreign = CrmLead::create(['organization_id' => $foreignOrg->id, 'assigned_to' => $this->member($foreignOrg, OrganizationRole::Owner)->id, 'first_name' => 'Foreign']);
        $base = "/crm/leads/{$lead->id}/meetings";
        $this->actingAs($owner);
        $input = ['type' => 'viewing', 'title' => 'Villa viewing', 'assigned_to' => $owner->id, 'starts_at' => '2027-01-02T10:00:00Z', 'ends_at' => '2027-01-02T11:00:00Z'];
        $id = $this->postJson($base, $input)->assertCreated()->assertJsonPath('meeting.version', 1)->assertJsonPath('meeting.permissions.cancel', true)->json('meeting.id');
        $this->getJson($base)->assertOk()->assertJsonPath('meetings.total', 1)->assertJsonPath('meetings.data.0.type', 'viewing');
        $this->getJson("/crm/leads/{$foreign->id}/meetings")->assertNotFound();
        $this->putJson("$base/$id", ['expected_version' => 1, 'title' => 'Rescheduled viewing', 'starts_at' => '2027-01-03T10:00:00Z', 'ends_at' => '2027-01-03T11:00:00Z'])->assertOk()->assertJsonPath('meeting.version', 2);
        $this->postJson("$base/$id/complete", ['expected_version' => 1, 'outcome' => 'Visited'])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->postJson("$base/$id/complete", ['expected_version' => 2, 'outcome' => 'Interested'])->assertOk()->assertJsonPath('meeting.status', 'completed')->assertJsonPath('meeting.version', 3);
        $this->postJson("$base/$id/cancel", ['expected_version' => 3])->assertUnprocessable()->assertJsonValidationErrors('status');
        $second = $this->postJson($base, $input)->assertCreated()->json('meeting.id');
        $this->postJson("$base/$second/cancel", ['expected_version' => 1])->assertOk()->assertJsonPath('meeting.status', 'cancelled')->assertJsonPath('meeting.version', 2);
    }
}
