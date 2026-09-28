<?php

namespace Tests\Feature\Notifications;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Notifications\Actions\GenerateDailyNotifications;
use App\Domain\Notifications\Models\OrganizationNotification;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\User;
use App\Models\VendorBill;
use App\Support\CurrentOperationalAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_reminders_exclude_completed_converted_and_foreign_follow_ups(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(10));
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $lead = $org->leads()->create(['first_name' => 'Reminder', 'last_name' => 'Lead']);
        $create = fn ($subject, $due, $completed = null) => $subject->activities()->create(['organization_id' => $subject->organization_id, 'created_by' => $manager->id, 'type' => 'call', 'due_at' => $due, 'completed_at' => $completed]);
        $overdue = $create($lead, now()->subHour());
        $create($lead, now()->addHour());
        $create($lead, now()->addDays(2));
        $create($lead, now()->subHour(), now());
        $converted = $org->leads()->create(['first_name' => 'Converted', 'last_name' => 'Lead', 'converted_at' => now()]);
        $create($converted, now()->subHour());
        $foreign = $other->leads()->create(['first_name' => 'Foreign', 'last_name' => 'Lead']);
        $create($foreign, now()->subHour());
        $alerts = collect(app(CurrentOperationalAlerts::class)->forOrganization($org->id))->keyBy('category');
        $this->assertSame(1, $alerts['overdue_crm_follow_ups']['count']);
        $this->assertSame(1, $alerts['due_crm_follow_ups']['count']);
        $this->assertSame(2, app(GenerateDailyNotifications::class)->handle());
        $this->assertSame(0, app(GenerateDailyNotifications::class)->handle());
        $this->assertDatabaseHas('organization_notifications', ['organization_id' => $org->id, 'user_id' => $manager->id, 'category' => 'overdue_crm_follow_ups', 'count' => 1]);
        $overdue->update(['completed_at' => now()]);
        $alerts = collect(app(CurrentOperationalAlerts::class)->forOrganization($org->id))->keyBy('category');
        $this->assertSame(0, $alerts['overdue_crm_follow_ups']['count']);
    }

    public function test_daily_generation_is_tenant_scoped_deduplicated_and_respects_preferences(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $member = User::factory()->create(['current_organization_id' => $organization->id]);
        $otherUser = User::factory()->create(['current_organization_id' => $other->id]);
        $organization->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $organization->users()->attach($member, ['role' => OrganizationRole::Member->value]);
        $other->users()->attach($otherUser, ['role' => OrganizationRole::Owner->value]);
        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Vendor']);
        VendorBill::create(['organization_id' => $organization->id, 'vendor_id' => $vendor->id, 'reference' => 'BIL-OVERDUE', 'description' => 'Service', 'status' => 'posted', 'bill_date' => today()->subWeek(), 'due_on' => today()->subDay(), 'total' => 100]);

        $this->actingAs($member)->put(route('notifications.preferences.update'), ['daily_digest_enabled' => true, 'enabled_categories' => ['overdue_vendor_bills']])->assertForbidden();
        $this->actingAs($owner)->put(route('notifications.preferences.update'), ['daily_digest_enabled' => true, 'enabled_categories' => ['overdue_vendor_bills']])->assertRedirect();
        $this->assertSame(2, app(GenerateDailyNotifications::class)->handle());
        $this->assertSame(0, app(GenerateDailyNotifications::class)->handle());
        $this->assertDatabaseCount('organization_notifications', 2);
        $this->assertDatabaseMissing('organization_notifications', ['user_id' => $otherUser->id]);

        $notification = OrganizationNotification::where('user_id', $owner->id)->sole();
        $this->actingAs($member)->post(route('notifications.read', $notification))->assertNotFound();
        $this->actingAs($owner)->post(route('notifications.read', $notification))->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);
        $this->actingAs($owner)->put(route('notifications.preferences.update'), ['daily_digest_enabled' => false, 'enabled_categories' => []])->assertRedirect();
        $this->travel(1)->days();
        $this->assertSame(0, app(GenerateDailyNotifications::class)->handle());
    }

    public function test_invalid_category_and_foreign_notification_cannot_be_used(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $otherUser = User::factory()->create(['current_organization_id' => $other->id]);
        $organization->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $other->users()->attach($otherUser, ['role' => OrganizationRole::Owner->value]);
        $notification = OrganizationNotification::create(['organization_id' => $other->id, 'user_id' => $otherUser->id, 'category' => 'overdue_invoices', 'event_key' => 'test', 'title' => 'Overdue invoices', 'count' => 1, 'href' => '/invoices']);

        $this->actingAs($owner)->put(route('notifications.preferences.update'), ['daily_digest_enabled' => true, 'enabled_categories' => ['foreign_category']])->assertSessionHasErrors('enabled_categories.0');
        $this->actingAs($owner)->post(route('notifications.read', $notification))->assertNotFound();
        $this->actingAs($owner)->get(route('notifications.index'))->assertOk();
    }
}
