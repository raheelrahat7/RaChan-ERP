<?php

namespace Tests\Feature\RealEstate;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingPhase2bTest extends TestCase
{
    use RefreshDatabase;

    public function test_secondary_fields_parties_and_status_tabs_are_organization_scoped_and_versioned(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Marina', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $org->id, 'property_id' => $property->id, 'number' => '1204', 'type' => 'apartment', 'status' => 'available']);
        $seller = Owner::create(['organization_id' => $org->id, 'name' => 'Seller A']);
        $buyer = CrmContact::create(['organization_id' => $org->id, 'first_name' => 'Buyer', 'last_name' => 'B']);
        $foreignBuyer = CrmContact::create(['organization_id' => $other->id, 'first_name' => 'Foreign', 'last_name' => 'Buyer']);
        $this->actingAs($manager);

        $listing = $this->postJson('/real-estate/listings', ['unit_id' => $unit->id, 'purpose' => 'sale', 'market_segment' => 'secondary', 'price' => 1000000, 'valuation_price' => 950000, 'owner_id' => $seller->id, 'buyer_contact_id' => $buyer->id, 'mortgage_status' => 'preapproved', 'noc_status' => 'pending', 'transfer_status' => 'awaiting_documents'])
            ->assertCreated()->assertJsonPath('listing.version', 1)->assertJsonPath('listing.seller.name', 'Seller A')->assertJsonPath('listing.buyer.first_name', 'Buyer')->json('listing');
        $this->getJson('/real-estate/listings/data?market_segment=secondary&workflow_status=draft')
            ->assertOk()->assertJsonCount(1, 'listings.data')->assertJsonPath('secondaryStatusSummary.0.code', 'draft')->assertJsonPath('secondaryStatusSummary.0.total', 1);
        $this->putJson('/real-estate/listings/'.$listing['id'], ['expected_version' => 1, 'transfer_status' => 'scheduled', 'valuation_price' => '975000.00'])
            ->assertOk()->assertJsonPath('listing.version', 2)->assertJsonPath('listing.transfer_status', 'scheduled')->assertJsonPath('listing.valuation_price', '975000.00');
        $this->putJson('/real-estate/listings/'.$listing['id'], ['expected_version' => 1, 'noc_status' => 'issued'])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson('/real-estate/listings/'.$listing['id'], ['expected_version' => 2, 'buyer_contact_id' => $foreignBuyer->id])->assertUnprocessable()->assertJsonValidationErrors('buyer_contact_id');
    }

    public function test_secondary_fields_cannot_be_added_to_primary_listings(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Tower', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $org->id, 'property_id' => $property->id, 'number' => '1', 'type' => 'apartment', 'status' => 'available']);
        $this->actingAs($manager);

        $this->postJson('/real-estate/listings', ['unit_id' => $unit->id, 'purpose' => 'sale', 'price' => 100, 'valuation_price' => 90])->assertUnprocessable()->assertJsonValidationErrors('valuation_price');
    }
}
