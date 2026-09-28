<?php

namespace Tests\Feature\Fleet;

use App\Domain\Fleet\Actions\ManageFleet;
use App\Domain\Fleet\Models\FleetAssignment;
use App\Domain\Fleet\Models\FleetService;
use App\Domain\Fleet\Models\FleetVehicle;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FleetTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private User $technician;

    private FleetVehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->organization = Organization::factory()->create();
        $this->manager = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->technician = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->organization->users()->attach($this->manager, ['role' => OrganizationRole::Manager->value]);
        $this->organization->users()->attach($this->technician, ['role' => OrganizationRole::Member->value]);
        $this->vehicle = app(ManageFleet::class)->vehicle($this->organization, $this->manager, ['reference' => 'CAR-1', 'plate' => 'abc 123', 'make' => 'Sample', 'model' => 'Service van', 'odometer' => 100]);
        $this->actingAs($this->manager);
    }

    public function test_assignments_cannot_overlap_and_retirement_preserves_return_history(): void
    {
        $input = ['user_id' => $this->technician->id, 'reason' => 'Field work'];
        $this->post(route('fleet.assign', $this->vehicle), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('fleet.assign', $this->vehicle), $input)->assertSessionHasErrors('vehicle');
        $assignment = FleetAssignment::sole();
        $this->post(route('fleet.status', $this->vehicle), ['status' => 'retired', 'reason' => 'Disposal'])->assertSessionHasErrors('vehicle');
        $this->post(route('fleet.return', $assignment), ['reason' => 'Field work ended'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('fleet.status', $this->vehicle), ['status' => 'retired', 'reason' => 'Disposal'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('fleet.assign', $this->vehicle), $input)->assertSessionHasErrors('vehicle');
        $this->assertNotNull($assignment->fresh()->ended_at);
        $this->assertSame('Field work ended', $assignment->fresh()->return_reason);
        $this->assertSame('ABC 123', $this->vehicle->plate);
        $this->get(route('fleet.show', $this->vehicle))->assertInertia(fn (Assert $page) => $page->component('fleet/Vehicle')->has('assignments.data', 1));
    }

    public function test_linked_service_requires_job_completion_and_never_reduces_odometer_or_posts_finance(): void
    {
        $property = Property::create(['organization_id' => $this->organization->id, 'name' => 'Garage', 'type' => 'commercial']);
        $job = MaintenanceRequest::create(['organization_id' => $this->organization->id, 'property_id' => $property->id, 'reference' => 'JOB', 'title' => 'Vehicle repair', 'requires_manager_confirmation' => true, 'submitted_at' => now(), 'status' => 'in_progress']);
        $input = ['kind' => 'repair', 'description' => 'Repair', 'due_on' => today()->subDay()->format('Y-m-d'), 'maintenance_request_id' => $job->id, 'operation_key' => (string) Str::uuid()];
        $this->post(route('fleet.service', $this->vehicle), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('fleet.service', $this->vehicle), $input)->assertRedirect()->assertSessionHasNoErrors();
        $service = FleetService::sole();
        $this->get(route('fleet.show', $this->vehicle))->assertInertia(fn (Assert $page) => $page->where('services.data.0.overdue', true));
        $this->post(route('fleet.complete', $service), ['odometer' => 101, 'note' => 'Finished'])->assertSessionHasErrors('maintenance_request_id');
        $job->update(['status' => 'completed', 'completed_at' => now()]);
        $this->post(route('fleet.complete', $service), ['odometer' => 99, 'note' => 'Finished'])->assertSessionHasErrors('odometer');
        $this->post(route('fleet.complete', $service), ['odometer' => 101, 'note' => 'Finished'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('fleet.complete', $service), ['odometer' => 101, 'note' => 'Finished'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(101, $this->vehicle->fresh()->odometer);
        $this->assertDatabaseCount('fleet_services', 1);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_fleet_scope_and_foreign_member_assignment_and_duplicate_plates_are_enforced(): void
    {
        $outsider = User::factory()->create();
        $this->post(route('fleet.assign', $this->vehicle), ['user_id' => $outsider->id, 'reason' => 'Not a member'])->assertNotFound();
        $this->post(route('fleet.store'), ['reference' => 'CAR-2', 'plate' => 'ABC 123', 'make' => 'Sample', 'model' => 'Duplicate', 'odometer' => 0])->assertSessionHasErrors('plate');
        $foreign = FleetVehicle::create(['organization_id' => Organization::factory()->create()->id, 'reference' => 'OTHER', 'plate' => 'OTHER', 'make' => 'Other', 'model' => 'Other', 'odometer' => 0]);
        $this->get(route('fleet.show', $foreign))->assertNotFound();
        $this->actingAs($this->technician)->get(route('fleet.index'))->assertForbidden();
        $this->post(route('fleet.assign', $this->vehicle), ['user_id' => $this->technician->id, 'reason' => 'Denied'])->assertForbidden();
    }
}
