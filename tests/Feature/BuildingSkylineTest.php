<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Building;
use App\Models\CrmContact;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\SalesContract;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BuildingSkylineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function owner(Organization $org): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => OrganizationRole::Owner->value]);

        return $user;
    }

    public function test_skyline_uses_real_workflows_and_recorded_floor_order(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->owner($org);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Skyline House', 'type' => 'residential']);
        $building = Building::create(['organization_id' => $org->id, 'property_id' => $property->id, 'name' => 'Tower A', 'floors' => 3]);
        $sold = $this->unit($org, $property, $building, 'S', '3');
        $overdue = $this->unit($org, $property, $building, 'O', '2');
        $maintenance = $this->unit($org, $property, $building, 'M', '1');
        $let = $this->unit($org, $property, $building, 'L', 'G');
        $reserved = $this->unit($org, $property, $building, 'R', 'P1');
        $this->unit($org, $property, $building, 'V', null);
        SalesContract::create(['organization_id' => $org->id, 'unit_id' => $sold->id, 'reference' => 'SKY-SALE', 'status' => 'active', 'contracted_on' => today()->toDateString()]);
        $contact = CrmContact::create(['organization_id' => $org->id, 'first_name' => 'Ada', 'last_name' => 'Tenant']);
        $lease = Lease::create(['organization_id' => $org->id, 'unit_id' => $overdue->id, 'contact_id' => $contact->id,
            'reference' => 'SKY-LEASE-O', 'status' => 'active', 'starts_on' => today()->subMonth()->toDateString(), 'ends_on' => today()->addMonth()->toDateString()]);
        Lease::create(['organization_id' => $org->id, 'unit_id' => $let->id, 'contact_id' => $contact->id,
            'reference' => 'SKY-LEASE-L', 'status' => 'active', 'starts_on' => today()->subMonth()->toDateString(), 'ends_on' => today()->addMonth()->toDateString()]);
        MaintenanceRequest::create(['organization_id' => $org->id, 'property_id' => $property->id, 'unit_id' => $maintenance->id,
            'reference' => 'SKY-JOB', 'title' => 'Leak', 'status' => 'open']);
        Reservation::create(['organization_id' => $org->id, 'unit_id' => $reserved->id, 'reference' => 'SKY-RES', 'status' => 'active', 'expires_at' => now()->addDay()]);
        $invoice = Invoice::create(['organization_id' => $org->id, 'contact_id' => $contact->id, 'reference' => 'SKY-RENT',
            'status' => 'posted', 'accounting_treatment' => 'revenue', 'due_on' => today()->subDay()->toDateString(), 'total' => 100, 'currency' => 'AED']);

        $this->actingAs($owner)->post(route('inventory.rent-invoices.store', $lease->id), ['invoice_id' => $invoice->id, 'reason' => 'Monthly rent'])->assertRedirect();
        $response = $this->getJson(route('inventory.buildings.skyline', $building->id))->assertOk();
        $response->assertJsonPath('building.name', 'Tower A')
            ->assertJsonPath('floors.0.label', '3')->assertJsonPath('floors.0.units.0.status', 'sold')
            ->assertJsonPath('floors.1.label', '2')->assertJsonPath('floors.1.units.0.status', 'rent_overdue')
            ->assertJsonPath('floors.2.label', '1')->assertJsonPath('floors.2.units.0.status', 'maintenance')
            ->assertJsonPath('floors.3.label', 'G')->assertJsonPath('floors.3.units.0.status', 'let')
            ->assertJsonPath('floors.4.label', 'P1')->assertJsonPath('floors.4.units.0.status', 'reserved')
            ->assertJsonPath('floors.5.label', 'Unassigned')->assertJsonPath('floors.5.units.0.status', 'vacant');
        $this->get(route('inventory.properties.show', $property->id))->assertInertia(fn (Assert $page) => $page->has('skylines', 1)->where('skylines.0.building.id', $building->id)->etc());

        Payment::create(['organization_id' => $org->id, 'invoice_id' => $invoice->id, 'reference' => 'SKY-PAY', 'amount' => 100, 'currency' => 'AED', 'received_on' => today()->toDateString()]);
        $this->getJson(route('inventory.buildings.skyline', $building->id))->assertJsonPath('floors.1.units.0.status', 'let');
        $this->delete(route('inventory.rent-invoices.destroy', [$lease->id, $invoice->id]), ['reason' => 'Corrected rent mapping'])->assertRedirect();
        $this->assertDatabaseCount('lease_rent_invoices', 0);
        $this->assertSame(2, DB::table('audit_logs')->whereIn('event', ['leasing.rent_invoice.linked', 'leasing.rent_invoice.unlinked'])->count());
    }

    public function test_floor_and_rent_link_reject_foreign_or_inaccurate_data(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $owner = $this->owner($org);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Own', 'type' => 'residential']);
        $building = Building::create(['organization_id' => $org->id, 'property_id' => $property->id, 'name' => 'Tower', 'floors' => 2]);
        $unit = $this->unit($org, $property, $building, 'A', null);
        $foreignProperty = Property::create(['organization_id' => $other->id, 'name' => 'Foreign', 'type' => 'residential']);
        $foreignBuilding = Building::create(['organization_id' => $other->id, 'property_id' => $foreignProperty->id, 'name' => 'Other', 'floors' => 2]);
        $contact = CrmContact::create(['organization_id' => $org->id, 'first_name' => 'Ada', 'last_name' => 'Tenant']);
        $lease = Lease::create(['organization_id' => $org->id, 'unit_id' => $unit->id, 'contact_id' => $contact->id,
            'reference' => 'SKY-VALID', 'status' => 'active', 'starts_on' => today()->toDateString(), 'ends_on' => today()->addMonth()->toDateString()]);
        $invoice = Invoice::create(['organization_id' => $other->id, 'reference' => 'SKY-FOREIGN', 'status' => 'posted',
            'accounting_treatment' => 'revenue', 'due_on' => today()->subDay()->toDateString(), 'total' => 100, 'currency' => 'AED']);

        $this->actingAs($owner)->getJson(route('inventory.buildings.skyline', $foreignBuilding->id))->assertNotFound();
        $this->put(route('inventory.units.floor.update', $unit->id), ['floor' => '3'])->assertSessionHasErrors(['floor']);
        $this->put(route('inventory.units.floor.update', $unit->id), ['floor' => 'P1'])->assertRedirect();
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'floor' => 'P1']);
        $this->post(route('inventory.rent-invoices.store', $lease->id), ['invoice_id' => $invoice->id, 'reason' => 'Wrong'])->assertNotFound();
        $this->assertDatabaseCount('lease_rent_invoices', 0);
    }

    private function unit(Organization $org, Property $property, Building $building, string $number, ?string $floor): Unit
    {
        return Unit::create(['organization_id' => $org->id, 'property_id' => $property->id, 'building_id' => $building->id,
            'number' => $number, 'floor' => $floor, 'type' => 'apartment', 'status' => 'available']);
    }
}
