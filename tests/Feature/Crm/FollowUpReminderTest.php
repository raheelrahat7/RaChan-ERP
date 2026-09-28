<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Actions\SendFollowUpReminders;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Notifications\Models\OrganizationNotification;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class FollowUpReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_reminder_targets_current_assignee_once_on_local_day(): void
    {
        $this->travelTo('2026-09-20 04:00:00');
        $org = Organization::factory()->create(['timezone' => 'Asia/Dubai']);
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $agent = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $org->users()->attach($agent, ['role' => OrganizationRole::Member->value]);
        $lead = $org->leads()->create(['first_name' => 'Upcoming', 'last_name' => 'Lead', 'assigned_to' => $agent->id]);
        $activity = $lead->activities()->create(['organization_id' => $org->id, 'type' => 'task', 'due_at' => '2026-09-22 06:00:00']);
        $reminders = app(SendFollowUpReminders::class);
        $this->assertSame(0, $reminders->handle($org->fresh()));
        $this->actingAs($agent)->put(route('crm.assignment.follow-up-reminder'), ['reminder_days' => 2])->assertForbidden();
        $this->actingAs($owner)->put(route('crm.assignment.follow-up-reminder'), ['reminder_days' => 2])->assertRedirect();
        $this->get(route('crm.assignment.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('followUpReminderDays', 2));
        $this->assertSame(1, $reminders->handle($org->fresh()));
        $this->assertSame(0, $reminders->handle($org->fresh()));
        $notification = OrganizationNotification::where('category', 'crm_follow_up_reminder')->sole();
        $this->assertSame($agent->id, $notification->user_id);
        $this->assertStringContainsString('2 days', $notification->title);
        $this->assertDatabaseHas('audit_logs', ['event' => 'crm.follow_up.reminder_sent', 'subject_id' => $notification->id]);
        $activity->update(['completed_at' => now()]);
        $this->assertSame(0, $reminders->handle($org->fresh()));
        $this->put(route('crm.assignment.follow-up-reminder'), ['reminder_days' => null])->assertRedirect();
        $this->assertNull($org->fresh()->crm_follow_up_reminder_days);
    }

    public function test_reminder_skips_ineligible_tasks_and_uses_local_eight_am(): void
    {
        $this->travelTo('2026-09-20 03:00:00');
        $org = Organization::factory()->create(['timezone' => 'Asia/Dubai', 'crm_follow_up_reminder_days' => 1]);
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $agent = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $org->users()->attach($agent, ['role' => OrganizationRole::Member->value]);
        $due = '2026-09-21 06:00:00';
        $open = $org->leads()->create(['first_name' => 'Open', 'last_name' => 'Lead', 'assigned_to' => $agent->id]);
        $open->activities()->create(['organization_id' => $org->id, 'type' => 'task', 'due_at' => $due]);
        $completed = $org->leads()->create(['first_name' => 'Completed', 'last_name' => 'Lead', 'assigned_to' => $agent->id]);
        $completed->activities()->create(['organization_id' => $org->id, 'type' => 'task', 'due_at' => $due, 'completed_at' => now()]);
        $unassigned = $org->leads()->create(['first_name' => 'Unassigned', 'last_name' => 'Lead']);
        $unassigned->activities()->create(['organization_id' => $org->id, 'type' => 'task', 'due_at' => $due]);
        $converted = $org->leads()->create(['first_name' => 'Converted', 'last_name' => 'Lead', 'assigned_to' => $agent->id, 'converted_at' => now()]);
        $converted->activities()->create(['organization_id' => $org->id, 'type' => 'task', 'due_at' => $due]);
        $reminders = app(SendFollowUpReminders::class);
        $this->assertSame(0, $reminders->handle($org));
        $this->travelTo('2026-09-20 04:00:00');
        $this->assertSame(1, $reminders->handle($org));
        $this->assertDatabaseCount('organization_notifications', 1);
    }
}
