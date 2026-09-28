<?php

namespace Tests\Feature\Operations;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Operations\Actions\ManageMaintenance;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_only_updates_preserve_assignment_and_repeated_completion_keeps_its_date(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Local', 'type' => 'residential']);
        $item = MaintenanceRequest::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'reference' => 'MNT-ASSIGNED', 'title' => 'Assigned', 'assigned_to' => $manager->id]);
        $this->actingAs($manager)->put(route('maintenance.status.update', $item), ['status' => 'completed'])->assertRedirect();
        $completedAt = $item->fresh()->completed_at->toDateTimeString();
        $this->travel(1)->days();
        $this->put(route('maintenance.status.update', $item), ['status' => 'completed'])->assertRedirect();
        $this->assertSame($manager->id, $item->fresh()->assigned_to);
        $this->assertSame($completedAt, $item->fresh()->completed_at->toDateTimeString());
        $this->put(route('maintenance.status.update', $item), ['status' => 'open', 'assigned_to' => null, 'reason' => 'Further service needed'])->assertRedirect();
        $this->assertNull($item->fresh()->assigned_to);
        $this->assertNull($item->fresh()->completed_at);
    }

    public function test_creation_rolls_back_if_auditing_fails(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Local', 'type' => 'residential']);
        $audit = \Mockery::mock(RecordOrganizationAuditLog::class);
        $audit->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(RecordOrganizationAuditLog::class, $audit);
        try {
            app(ManageMaintenance::class)->create($organization, $manager, ['property_id' => $property->id, 'title' => 'Repair', 'priority' => 'high']);
            $this->fail('Expected auditing to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('maintenance_requests', 0);
    }

    public function test_a_manager_can_create_and_complete_a_tenant_maintenance_request(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Palm Court', 'type' => 'residential']);

        $this->actingAs($manager)->post(route('maintenance.store'), ['property_id' => $property->id, 'title' => 'Repair air conditioning', 'priority' => 'high'])->assertRedirect();
        $request = MaintenanceRequest::sole();
        $this->actingAs($manager)->put(route('maintenance.status.update', $request), ['status' => 'completed'])->assertRedirect();

        $this->assertDatabaseHas('maintenance_requests', ['id' => $request->id, 'status' => 'completed']);
    }

    public function test_a_manager_cannot_update_another_tenants_maintenance_request(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $otherOrganization->id, 'name' => 'Other', 'type' => 'commercial']);
        $request = MaintenanceRequest::create(['organization_id' => $otherOrganization->id, 'property_id' => $property->id, 'reference' => 'MNT-OTHER', 'title' => 'Private request']);

        $this->actingAs($manager)->put(route('maintenance.status.update', $request), ['status' => 'completed'])->assertNotFound();
    }

    public function test_a_manager_cannot_assign_a_request_to_a_user_from_another_tenant(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $otherUser = User::factory()->create();
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $otherOrganization->users()->attach($otherUser, ['role' => OrganizationRole::Member->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Local', 'type' => 'residential']);
        $request = MaintenanceRequest::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'reference' => 'MNT-LOCAL', 'title' => 'Local request']);

        $this->actingAs($manager)->put(route('maintenance.status.update', $request), ['status' => 'open', 'assigned_to' => $otherUser->id])->assertSessionHasErrors('assigned_to');
        $this->assertNull($request->fresh()->assigned_to);
    }

    public function test_a_manager_can_assign_a_tenant_vendor_and_track_work_order_costs(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Local', 'type' => 'residential']);
        $request = MaintenanceRequest::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'reference' => 'MNT-COST', 'title' => 'Local request']);

        $this->actingAs($manager)->post(route('maintenance.vendors.store'), ['name' => 'Cool Air', 'trade' => 'HVAC'])->assertRedirect();
        $vendor = MaintenanceVendor::sole();
        $this->actingAs($manager)->put(route('maintenance.work-order.update', $request), ['vendor_id' => $vendor->id, 'estimated_cost' => 500, 'actual_cost' => 475])->assertRedirect();

        $this->assertDatabaseHas('maintenance_requests', ['id' => $request->id, 'vendor_id' => $vendor->id, 'estimated_cost' => 500, 'actual_cost' => 475]);
    }
}
