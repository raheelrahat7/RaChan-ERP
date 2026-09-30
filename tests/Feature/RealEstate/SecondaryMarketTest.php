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
