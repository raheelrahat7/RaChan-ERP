<?php

namespace Tests\Feature\RealEstate;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Listing;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SecondaryMarketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_new_organization_receives_empty_inventory_and_permission_state(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);

        $this->actingAs($owner)->get(route('real-estate.secondary-market.index'))->assertInertia(fn (Assert $page) => $page
            ->component('real-estate/Listings')
            ->has('units', 0)
            ->has('listings', 0)
            ->where('canManage', true)
            ->where('canManageInventory', true));

        $this->get(route('reservations.index'))->assertInertia(fn (Assert $page) => $page
            ->component('transactions/Reservations')
            ->has('units', 0)
            ->has('listings', 0)
            ->where('canManageTransactions', true)
            ->where('canManageInventory', true));
    }

    public function test_listing_can_create_property_building_and_unit_in_one_step(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);

        $this->actingAs($owner)->post(route('real-estate.listings.store'), [
            'inventory_mode' => 'new_unit', 'property_name' => 'Marina Tower', 'property_type' => 'residential', 'property_city' => 'Dubai',
            'building_name' => 'Tower A', 'building_floors' => 10, 'floor' => '3', 'unit_number' => '301', 'unit_type' => 'apartment',
            'purpose' => 'sale', 'market_segment' => 'secondary', 'price' => 1200000,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $unit = Unit::where('number', '301')->sole();
        $this->assertSame('available', $unit->status);
        $this->assertDatabaseHas('buildings', ['name' => 'Tower A', 'property_id' => $unit->property_id]);
        $this->assertDatabaseHas('listings', ['unit_id' => $unit->id, 'purpose' => 'sale', 'market_segment' => 'secondary']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'listing.created']);

        $this->post(route('real-estate.listings.store'), [
            'inventory_mode' => 'new_unit', 'property_id' => $unit->property_id, 'unit_number' => '301', 'unit_type' => 'apartment', 'purpose' => 'rent', 'price' => 1,
        ])->assertSessionHasErrors('unit_number');
        $this->assertSame(1, Listing::count());
    }

    public function test_invalid_floor_rolls_back_all_new_listing_inventory(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);

        $this->actingAs($owner)->post(route('real-estate.listings.store'), [
            'inventory_mode' => 'new_unit', 'property_name' => 'Rollback Tower', 'property_type' => 'residential',
            'building_name' => 'Rollback Building', 'building_floors' => 2, 'floor' => '3',
            'unit_number' => '301', 'unit_type' => 'apartment', 'purpose' => 'rent', 'price' => 1000,
        ])->assertSessionHasErrors('floor');

        $this->assertDatabaseMissing('properties', ['organization_id' => $org->id, 'name' => 'Rollback Tower']);
        $this->assertDatabaseMissing('buildings', ['organization_id' => $org->id, 'name' => 'Rollback Building']);
        $this->assertDatabaseMissing('units', ['organization_id' => $org->id]);
        $this->assertDatabaseMissing('listings', ['organization_id' => $org->id]);
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $org->id, 'event' => 'listing.created']);
    }

    public function test_listing_creating_new_inventory_requires_inventory_permission(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => OrganizationRole::Member->value]);

        $this->actingAs($user)->post(route('real-estate.listings.store'), [
            'inventory_mode' => 'new_unit', 'property_name' => 'X', 'property_type' => 'residential', 'unit_number' => '1', 'unit_type' => 'apartment', 'purpose' => 'rent', 'price' => 1,
        ])->assertForbidden();
        $this->assertSame(0, Property::count());
    }

    public function test_secondary_market_includes_resales_and_rentals_but_excludes_primary_sales_and_other_organizations(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Harbor', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $org->id, 'property_id' => $property->id, 'number' => 'H-1', 'type' => 'apartment', 'status' => 'available']);

        $this->actingAs($manager)->post(route('real-estate.listings.store'), ['unit_id' => $unit->id, 'purpose' => 'sale', 'market_segment' => 'secondary', 'price' => 900000])->assertRedirect();
        $resale = Listing::where('purpose', 'sale')->sole();
        $this->post(route('real-estate.listings.store'), ['unit_id' => $unit->id, 'purpose' => 'rent', 'price' => 90000])->assertRedirect();
        $this->assertDatabaseHas('listings', ['organization_id' => $org->id, 'purpose' => 'rent', 'market_segment' => 'secondary']);
        $this->post(route('real-estate.listings.store'), ['unit_id' => $unit->id, 'purpose' => 'sale', 'market_segment' => 'primary', 'price' => 1000000])->assertRedirect();
        $this->post(route('real-estate.listings.store'), ['unit_id' => $unit->id, 'purpose' => 'rent', 'market_segment' => 'primary', 'price' => 100000])->assertSessionHasErrors('market_segment');
        Listing::create(['organization_id' => $org->id, 'unit_id' => $unit->id, 'reference' => 'LEGACY-RENT', 'purpose' => 'rent', 'market_segment' => null, 'price' => 80000]);

        $this->get(route('real-estate.secondary-market.index'))->assertInertia(fn (Assert $page) => $page
            ->component('real-estate/Listings')->where('marketSegment', 'secondary')->has('listings', 3));
        $this->get(route('real-estate.listings.index', ['market_segment' => 'primary']))->assertInertia(fn (Assert $page) => $page
            ->where('marketSegment', 'primary')->has('listings', 1));
        $this->put(route('listings.market-segment.update', $resale), ['market_segment' => 'primary'])->assertRedirect();
        $this->get(route('real-estate.secondary-market.index'))->assertInertia(fn (Assert $page) => $page->has('listings', 2));
        $this->assertDatabaseHas('audit_logs', ['event' => 'listing.market_segment.updated', 'subject_id' => $resale->id]);

        $other = Organization::factory()->create();
        $outside = User::factory()->create(['current_organization_id' => $other->id]);
        $other->users()->attach($outside, ['role' => OrganizationRole::Viewer->value]);
        $this->actingAs($outside)->get(route('real-estate.secondary-market.index'))->assertInertia(fn (Assert $page) => $page->has('listings', 0));
        $this->post(route('real-estate.listings.store'), ['unit_id' => $unit->id, 'purpose' => 'sale', 'market_segment' => 'secondary', 'price' => 900000])->assertForbidden();
    }
}
