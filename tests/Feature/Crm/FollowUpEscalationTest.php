<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Actions\EscalateOverdueFollowUps;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Notifications\Models\OrganizationNotification;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class FollowUpEscalationTest extends TestCase
{
    use RefreshDatabase;

    public function test_opt_in_escalates_at_one_day_one_week_and_two_weeks_to_scoped_leadership(): void
    {
        $this->travelTo('2026-09-20 04:00:00');
        $org = Organization::factory()->create(['timezone' => 'Asia/Dubai']);
        $owner = $this->member($org, OrganizationRole::Owner);
        $admin = $this->member($org, OrganizationRole::Administrator);
        $manager = $this->member($org, OrganizationRole::Manager);
        $outsideManager = $this->member($org, OrganizationRole::Manager);
        $agent = $this->member($org, OrganizationRole::Member);
        $department = DB::table('crm_departments')->insertGetId(['organization_id' => $org->id, 'name' => 'Sales', 'active' => true]);
        $subdepartment = DB::table('crm_subdepartments')->insertGetId(['organization_id' => $org->id, 'department_id' => $department, 'name' => 'Homes', 'active' => true]);
        $team = DB::table('crm_teams')->insertGetId(['organization_id' => $org->id, 'subdepartment_id' => $subdepartment, 'name' => 'North', 'active' => true]);
        DB::table('crm_team_memberships')->insert(['organization_id' => $org->id, 'user_id' => $agent->id, 'team_id' => $team]);
        DB::table('crm_visibility_grants')->insert(['organization_id' => $org->id, 'user_id' => $manager->id, 'scope_type' => 'department', 'scope_id' => $department]);
        $lead = $org->leads()->create(['first_name' => 'Late', 'last_name' => 'Lead', 'assigned_to' => $agent->id]);
        $activity = $lead->activities()->create(['organization_id' => $org->id, 'type' => 'task', 'due_at' => now()]);
        $escalate = app(EscalateOverdueFollowUps::class);

        $this->travelTo('2026-09-21 04:00:00');
        $this->assertSame(0, $escalate->handle($org->fresh()));
        $this->actingAs($agent)->put(route('crm.assignment.follow-up-escalation'), ['enabled' => true])->assertForbidden();
        $this->actingAs($owner)->put(route('crm.assignment.follow-up-escalation'), ['enabled' => true])->assertRedirect();
        $this->get(route('crm.assignment.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('followUpEscalationEnabled', true));
        $this->assertSame(3, $escalate->handle($org->fresh()));
        $this->assertSame(0, $escalate->handle($org->fresh()));
        $this->assertEqualsCanonicalizing([$owner->id, $admin->id, $manager->id], OrganizationNotification::where('category', 'crm_follow_up_escalation')->pluck('user_id')->all());
        $this->assertStringContainsString('1 day', OrganizationNotification::where('user_id', $owner->id)->sole()->title);

        $this->travelTo('2026-09-27 04:00:00');
        $this->assertSame(3, $escalate->handle($org->fresh()));
        $this->assertSame(0, $escalate->handle($org->fresh()));
        $this->assertDatabaseCount('organization_notifications', 6);
        $this->travelTo('2026-10-04 04:00:00');
        $this->assertSame(3, $escalate->handle($org->fresh()));
        $this->assertSame(0, $escalate->handle($org->fresh()));
        $this->assertDatabaseCount('organization_notifications', 9);
        $this->assertSame(9, DB::table('audit_logs')->where('event', 'crm.follow_up.escalation_sent')->count());
        $activity->update(['completed_at' => now()]);
        $this->assertSame(0, $escalate->handle($org->fresh()));
        $this->put(route('crm.assignment.follow-up-escalation'), ['enabled' => false])->assertRedirect();
        $this->assertFalse($org->fresh()->crm_follow_up_escalation_enabled);
        $this->assertDatabaseHas('audit_logs', ['event' => 'crm.follow_up.escalation_configured']);
    }

    public function test_late_enablement_sends_only_latest_milestone_and_skips_ineligible_tasks(): void
    {
        $this->travelTo('2026-09-21 04:00:00');
        $org = Organization::factory()->create(['timezone' => 'Asia/Dubai', 'crm_follow_up_escalation_enabled' => true]);
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $late = $org->leads()->create(['first_name' => 'Late', 'last_name' => 'Lead', 'assigned_to' => $agent->id]);
        $late->activities()->create(['organization_id' => $org->id, 'type' => 'task', 'due_at' => now()->subDays(15)]);
        $recent = $org->leads()->create(['first_name' => 'Recent', 'last_name' => 'Lead', 'assigned_to' => $agent->id]);
        $recent->activities()->create(['organization_id' => $org->id, 'type' => 'task', 'due_at' => now()->subHours(23)]);
        $unassigned = $org->leads()->create(['first_name' => 'Unassigned', 'last_name' => 'Lead']);
        $unassigned->activities()->create(['organization_id' => $org->id, 'type' => 'task', 'due_at' => now()->subDays(15)]);
        $converted = $org->leads()->create(['first_name' => 'Converted', 'last_name' => 'Lead', 'assigned_to' => $agent->id, 'converted_at' => now()]);
        $converted->activities()->create(['organization_id' => $org->id, 'type' => 'task', 'due_at' => now()->subDays(15)]);
        $completed = $org->leads()->create(['first_name' => 'Completed', 'last_name' => 'Lead', 'assigned_to' => $agent->id]);
        $completed->activities()->create(['organization_id' => $org->id, 'type' => 'task', 'due_at' => now()->subDays(15), 'completed_at' => now()]);
        $escalate = app(EscalateOverdueFollowUps::class);
        $this->travelTo('2026-09-21 03:00:00');
        $this->assertSame(0, $escalate->handle($org));
        $this->travelTo('2026-09-21 04:00:00');
        $this->assertSame(1, $escalate->handle($org));
        $this->assertSame(0, $escalate->handle($org));
        $notification = OrganizationNotification::sole();
        $this->assertSame($owner->id, $notification->user_id);
        $this->assertStringContainsString('2 weeks', $notification->title);
    }

    private function member(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }
}
