<?php

namespace Tests\Feature\Transactions;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmLead;
use App\Models\Listing;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecondaryDealJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_rental_listing_and_its_lead_flow_to_a_lease_but_not_a_sale(): void
    {
        [$organization, $manager, $unit] = $this->context();
        $listing = $this->listing($organization, $unit, 'rent');
        $lead = CrmLead::create(['organization_id' => $organization->id, 'listing_id' => $listing->id, 'assigned_to' => $manager->id, 'first_name' => 'Amina', 'last_name' => 'Khan', 'status' => 'new']);

        $this->actingAs($manager)->post(route('reservations.store'), [
            'unit_id' => $unit->id,
            'listing_id' => $listing->id,
            'lead_id' => $lead->id,
            'expires_at' => now()->addDay()->toDateTimeString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $reservation = Reservation::sole();
        $this->assertSame($listing->id, $reservation->listing_id);
        $this->assertSame($lead->id, $reservation->lead_id);

        $this->actingAs($manager)->post(route('agreements.sales-contracts.store'), [
            'reservation_id' => $reservation->id,
            'contracted_on' => now()->toDateString(),
        ])->assertSessionHasErrors('reservation_id');
        $this->assertDatabaseCount('sales_contracts', 0);

        $this->actingAs($manager)->post(route('agreements.leases.store'), [
            'reservation_id' => $reservation->id,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addYear()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('leases', ['reservation_id' => $reservation->id, 'unit_id' => $unit->id]);
    }

    public function test_resale_listing_flows_to_a_sale_but_rejects_a_lease(): void
    {
        [$organization, $manager, $unit] = $this->context();
        $listing = $this->listing($organization, $unit, 'sale');
        $this->actingAs($manager)->post(route('reservations.store'), [
            'unit_id' => $unit->id,
            'listing_id' => $listing->id,
            'expires_at' => now()->addDay()->toDateTimeString(),
        ])->assertSessionHasNoErrors();
        $reservation = Reservation::sole();

        $this->actingAs($manager)->post(route('agreements.leases.store'), [
            'reservation_id' => $reservation->id,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addYear()->toDateString(),
        ])->assertSessionHasErrors('reservation_id');
        $this->actingAs($manager)->post(route('agreements.sales-contracts.store'), [
            'reservation_id' => $reservation->id,
            'contracted_on' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales_contracts', ['reservation_id' => $reservation->id, 'unit_id' => $unit->id]);
    }

    public function test_reservation_rejects_a_listing_for_another_unit_and_mismatched_lead(): void
    {
        [$organization, $manager, $unit] = $this->context();
        $otherUnit = Unit::create(['organization_id' => $organization->id, 'property_id' => $unit->property_id, 'number' => '102', 'type' => 'apartment']);
        $listing = $this->listing($organization, $otherUnit, 'rent');
        $payload = ['unit_id' => $unit->id, 'listing_id' => $listing->id, 'expires_at' => now()->addDay()->toDateTimeString()];
        $this->actingAs($manager)->post(route('reservations.store'), $payload)->assertSessionHasErrors('listing_id');

        $listing = $this->listing($organization, $unit, 'rent');
        $otherLead = CrmLead::create(['organization_id' => $organization->id, 'listing_id' => null, 'assigned_to' => $manager->id, 'first_name' => 'Wrong', 'last_name' => 'Listing', 'status' => 'new']);
        $this->actingAs($manager)->post(route('reservations.store'), [...$payload, 'listing_id' => $listing->id, 'lead_id' => $otherLead->id])->assertSessionHasErrors('lead_id');
        $this->assertDatabaseCount('reservations', 0);
    }

    /** @return array{Organization, User, Unit} */
    private function context(): array
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Harbour', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'number' => '101', 'type' => 'apartment']);

        return [$organization, $manager, $unit];
    }

    private function listing(Organization $organization, Unit $unit, string $purpose): Listing
    {
        return Listing::create(['organization_id' => $organization->id, 'unit_id' => $unit->id, 'reference' => 'LST-'.uniqid(), 'purpose' => $purpose, 'market_segment' => 'secondary', 'status' => 'active', 'price' => 500000]);
    }
}
