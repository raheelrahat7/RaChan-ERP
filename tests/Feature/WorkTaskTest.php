<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkTaskTest extends TestCase
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

    public function test_assigned_tasks_and_overdue_counts_are_scoped_and_completed_by_the_assignee(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->member($org, OrganizationRole::Owner);
        $assignee = $this->member($org, OrganizationRole::Viewer);
        $other = $this->member(Organization::factory()->create(), OrganizationRole::Viewer);
        $lead = CrmLead::create(['organization_id' => $org->id, 'assigned_to' => $assignee->id, 'first_name' => 'Task', 'last_name' => 'Lead']);
        $this->actingAs($manager)->post(route('tasks.store'), ['title' => 'Call client', 'assigned_to' => $assignee->id,
            'priority' => 'high', 'due_at' => now()->subDay()->toDateTimeString(), 'related_type' => 'lead', 'related_id' => $lead->id])->assertRedirect();
        $task = DB::table('work_tasks')->sole();
        $this->actingAs($assignee)->get(route('tasks.index'))->assertInertia(fn (Assert $page) => $page
            ->where('tasks.data.0.title', 'Call client')->where('canManage', false)->etc());
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('kpis.overdue_tasks.count', 1)->etc());
        $this->actingAs($other)->get(route('tasks.index'))->assertInertia(fn (Assert $page) => $page->has('tasks.data', 0)->etc());
        $this->actingAs($assignee)->post(route('tasks.complete', $task->id))->assertRedirect();
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('kpis.overdue_tasks.count', 0)->etc());
        $this->assertDatabaseHas('work_tasks', ['id' => $task->id, 'status' => 'completed', 'completed_by' => $assignee->id]);
    }

    public function test_foreign_links_and_unprivileged_creation_are_rejected(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = $this->member($org, OrganizationRole::Manager);
        $viewer = $this->member($org, OrganizationRole::Viewer);
        $foreign = CrmLead::create(['organization_id' => $other->id, 'first_name' => 'Foreign', 'last_name' => 'Lead']);
        $data = ['title' => 'Wrong link', 'assigned_to' => $viewer->id, 'related_type' => 'lead', 'related_id' => $foreign->id];
        $this->actingAs($viewer)->post(route('tasks.store'), $data)->assertForbidden();
        $this->actingAs($manager)->post(route('tasks.store'), $data)->assertSessionHasErrors(['related_id']);
        $this->assertDatabaseCount('work_tasks', 0);
    }
}
