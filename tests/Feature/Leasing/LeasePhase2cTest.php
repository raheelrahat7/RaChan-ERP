<?php

namespace Tests\Feature\Leasing;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Leasing\Models\LeaseCheque;
use App\Domain\Leasing\Models\LeaseEjariRegistration;
use App\Domain\Leasing\Models\LeaseSecurityDeposit;
use App\Models\HandoverChecklist;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Listing;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeasePhase2cTest extends TestCase
{
    use RefreshDatabase;

    public function test_lease_details_tabs_links_and_versioned_renewal(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Tower', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $org->id, 'property_id' => $property->id, 'number' => '101', 'type' => 'apartment', 'status' => 'leased']);
        $lease = Lease::create(['organization_id' => $org->id, 'unit_id' => $unit->id, 'reference' => 'LSE-2C', 'status' => 'active', 'starts_on' => today()->subYear(), 'ends_on' => today()->addDays(30), 'rent_amount' => 120000]);
        $invoice = Invoice::create(['organization_id' => $org->id, 'reference' => 'INV-2C', 'due_on' => today(), 'subtotal' => 10000, 'total' => 10000]);
        LeaseSecurityDeposit::create(['organization_id' => $org->id, 'lease_id' => $lease->id, 'invoice_id' => $invoice->id, 'required_amount' => 10000, 'due_on' => today(), 'created_by' => $manager->id]);
        LeaseCheque::create(['organization_id' => $org->id, 'lease_id' => $lease->id, 'cheque_number' => 'CHK-2C', 'bank_name' => 'Bank', 'payer_name' => 'Tenant', 'amount' => 5000, 'due_on' => today(), 'status' => 'scheduled', 'created_by' => $manager->id, 'updated_by' => $manager->id]);
        LeaseEjariRegistration::create(['organization_id' => $org->id, 'lease_id' => $lease->id, 'status' => 'registered', 'ejari_number' => 'EJ-2C', 'applied_on' => today(), 'registered_on' => today(), 'expires_on' => today()->addYear(), 'created_by' => $manager->id, 'updated_by' => $manager->id]);
        $this->actingAs($manager);

        $this->getJson('/agreements/leases/data?tab=renewal_due')->assertOk()->assertJsonCount(1, 'leases.data')->assertJsonPath('tabCounts.renewal_due', 1)->assertJsonPath('leases.data.0.security_deposit.required_amount', '10000.00')->assertJsonPath('leases.data.0.ejari.ejari_number', 'EJ-2C')->assertJsonPath('leases.data.0.cheques.0.cheque_number', 'CHK-2C');
        $this->putJson('/agreements/leases/'.$lease->id, ['expected_version' => 1, 'tenancy_number' => 'TN-001', 'advance_amount' => '25000.00'])->assertOk()->assertJsonPath('lease.version', 2)->assertJsonPath('lease.tenancy_number', 'TN-001');
        $this->putJson('/agreements/leases/'.$lease->id, ['expected_version' => 1, 'advance_amount' => 1])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->postJson('/agreements/leases/'.$lease->id.'/renew', ['expected_version' => 2, 'ends_on' => today()->addYear()->toDateString(), 'renewal_due_on' => today()->addMonths(11)->toDateString()])->assertOk()->assertJsonPath('lease.version', 3)->assertJsonPath('lease.renewal_due', false);
        $this->getJson('/agreements/leases/data?tab=renewed')->assertOk()->assertJsonPath('tabCounts.renewed', 1);
        $this->postJson('/agreements/leases/'.$lease->id.'/renew', ['expected_version' => 2, 'ends_on' => today()->addYears(2)->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        HandoverChecklist::create(['organization_id' => $org->id, 'lease_id' => $lease->id, 'type' => 'move_out', 'status' => 'completed', 'completed_at' => now(), 'created_by' => $manager->id]);
        $lease->update(['status' => 'completed', 'version' => 4]);
        $this->getJson('/agreements/leases/data?tab=moved_out')->assertOk()->assertJsonPath('tabCounts.moved_out', 1)->assertJsonPath('leases.data.0.move_out.status', 'completed');
    }

    public function test_lease_details_reject_cross_organization_records(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $other->id, 'name' => 'Other', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $other->id, 'property_id' => $property->id, 'number' => '1', 'type' => 'apartment', 'status' => 'available']);
        $lease = Lease::create(['organization_id' => $other->id, 'unit_id' => $unit->id, 'reference' => 'LSE-FOREIGN', 'starts_on' => today(), 'ends_on' => today()->addYear()]);
        $this->actingAs($manager);

        $this->getJson('/agreements/leases/'.$lease->id)->assertNotFound();
        $this->putJson('/agreements/leases/'.$lease->id, ['expected_version' => 1, 'tenancy_number' => 'X'])->assertNotFound();
    }

    public function test_json_create_returns_version_and_validates_tenancy_number(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Rental', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $org->id, 'property_id' => $property->id, 'number' => '1', 'type' => 'apartment', 'status' => 'reserved']);
        $listing = Listing::create(['organization_id' => $org->id, 'unit_id' => $unit->id, 'reference' => 'LST-RENT', 'purpose' => 'rent', 'market_segment' => 'secondary', 'status' => 'active', 'price' => 50000]);
        $reservation = Reservation::create(['organization_id' => $org->id, 'unit_id' => $unit->id, 'listing_id' => $listing->id, 'reference' => 'RES-RENT', 'status' => 'active', 'expires_at' => now()->addWeek(), 'created_by' => $manager->id]);
        $this->actingAs($manager);

        $this->postJson('/agreements/leases', ['reservation_id' => $reservation->id, 'starts_on' => today()->toDateString(), 'ends_on' => today()->addYear()->toDateString(), 'rent_amount' => '50000.00', 'tenancy_number' => 'TN-RENT', 'advance_amount' => '5000.00'])
            ->assertCreated()->assertJsonPath('lease.version', 1)->assertJsonPath('lease.tenancy_number', 'TN-RENT')->assertJsonPath('lease.advance_amount', '5000.00')->assertJsonPath('lease.permissions.edit', true);
        $this->postJson('/agreements/leases', ['reservation_id' => $reservation->id, 'starts_on' => today()->toDateString(), 'ends_on' => today()->addYear()->toDateString(), 'tenancy_number' => 'TN-RENT'])
            ->assertUnprocessable()->assertJsonValidationErrors('tenancy_number');
    }
}
