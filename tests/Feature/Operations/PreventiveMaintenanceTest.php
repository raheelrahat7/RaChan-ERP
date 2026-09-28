<?php

namespace Tests\Feature\Operations;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\PreventiveMaintenancePlan;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreventiveMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_due_plan_generates_a_vendor_assigned_work_order_and_advances_its_schedule(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Jumeirah', 'type' => 'residential']);
        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Cool Air']);
        $plan = PreventiveMaintenancePlan::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'vendor_id' => $vendor->id, 'title' => 'Quarterly HVAC', 'frequency_days' => 90, 'next_due_on' => today()->subDay()]);

        $this->actingAs($manager)->post(route('preventive-maintenance.generate', $plan), ['due_on' => $plan->next_due_on->toDateString()])->assertRedirect();

        $this->assertDatabaseHas('maintenance_requests', ['organization_id' => $organization->id, 'property_id' => $property->id, 'vendor_id' => $vendor->id, 'title' => 'Quarterly HVAC']);
        $this->assertSame(today()->addDays(89)->toDateString(), $plan->fresh()->next_due_on->toDateString());
    }
}
