<?php

namespace Tests\Feature\Finance;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PropertyProfitabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_users_see_only_their_property_operating_figures(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Marina', 'type' => 'residential']);
        $owner = Owner::create(['organization_id' => $organization->id, 'name' => 'Owner']);
        $property->owners()->attach($owner, ['ownership_share' => 100]);
        $unit = Unit::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'number' => '101', 'type' => 'apartment']);
        Lease::create(['organization_id' => $organization->id, 'unit_id' => $unit->id, 'reference' => 'LSE-PROFIT', 'status' => 'active', 'starts_on' => today(), 'ends_on' => today()->addYear(), 'rent_amount' => 120000]);
        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Vendor']);
        VendorBill::create(['organization_id' => $organization->id, 'vendor_id' => $vendor->id, 'property_id' => $property->id, 'reference' => 'BIL-PROFIT', 'description' => 'Service', 'status' => 'posted', 'bill_date' => today(), 'total' => 500]);
        MaintenanceRequest::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'reference' => 'MNT-PROFIT', 'title' => 'Repair', 'actual_cost' => 300]);
        Property::create(['organization_id' => $otherOrganization->id, 'name' => 'Private', 'type' => 'residential']);

        $response = $this->actingAs($manager)->get(route('reports.property-profitability'));
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('finance/PropertyProfitability')->has('properties', 1)->where('properties.0.name', 'Marina')->where('properties.0.active_rent', '120000.00')->where('properties.0.vendor_bill_commitments', '500.00')->where('properties.0.maintenance_actual', '300.00'));
    }
}
