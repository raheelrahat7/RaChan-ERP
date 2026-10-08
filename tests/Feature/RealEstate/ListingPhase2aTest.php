<?php

namespace Tests\Feature\RealEstate;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Listing;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingPhase2aTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_fields_filters_summary_and_versioned_updates(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Marina', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $org->id, 'property_id' => $property->id, 'number' => '1204', 'type' => 'apartment', 'status' => 'available']);
        $this->actingAs($manager);

        $listing = $this->postJson('/real-estate/listings', ['unit_id' => $unit->id, 'purpose' => 'sale', 'price' => '1200000.00', 'workflow_status' => 'draft', 'listing_category' => 'exclusive', 'emirate' => 'Dubai', 'community' => 'Marina', 'sub_community' => 'West', 'trakheesi_permit' => 'TR-9', 'bedrooms' => 2, 'bathrooms' => 3, 'size_sqft' => '1200.00', 'price_type' => 'fixed', 'portals' => ['Bayut']])->assertCreated()->assertJsonPath('listing.price_per_sqft', '1000.00')->assertJsonPath('listing.version', 1)->json('listing');
        $this->getJson('/real-estate/listings/data?emirate=Dubai&community=Marina')->assertOk()->assertJsonCount(1, 'listings.data')->assertJsonPath('emirateSummary.0.total', 1)->assertJsonPath('workflowStatuses.0.code', 'draft');
        $this->putJson('/real-estate/listings/'.$listing['id'], ['expected_version' => 1, 'dld_permit' => 'DLD-1', 'price_min' => '1000000.00', 'price_max' => '1400000.00'])->assertOk()->assertJsonPath('listing.version', 2)->assertJsonPath('listing.dld_permit', 'DLD-1');
        $this->putJson('/real-estate/listings/'.$listing['id'], ['expected_version' => 1, 'grade' => 'A'])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson('/real-estate/listings/'.$listing['id'], ['expected_version' => 2, 'price_min' => '2000000.00'])->assertUnprocessable()->assertJsonValidationErrors('price_max');
        $this->put('/real-estate/listings/'.$listing['id'].'/status', ['status' => 'active', 'expected_version' => 2])->assertRedirect();
        $this->getJson('/real-estate/listings/'.$listing['id'])->assertOk()->assertJsonPath('listing.version', 3)->assertJsonPath('listing.status', 'active')->assertJsonPath('listing.workflow_status', 'draft');
        $legacyUnit = Unit::create(['organization_id' => $org->id, 'property_id' => $property->id, 'number' => '1205', 'type' => 'apartment', 'status' => 'available']);
        Listing::create(['organization_id' => $org->id, 'unit_id' => $legacyUnit->id, 'reference' => 'LST-LEGACY', 'purpose' => 'sale', 'price' => 500000]);
        $this->getJson('/real-estate/listings/data?workflow_status=draft')->assertOk()->assertJsonCount(2, 'listings.data');
    }

    public function test_workflow_statuses_are_editable_and_organization_isolated(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $this->actingAs($manager);

        $status = $this->postJson('/real-estate/listings/workflow-statuses', ['code' => 'legal_review', 'name' => 'Legal Review', 'position' => 14, 'active' => true])->assertCreated()->assertJsonPath('workflowStatus.version', 1)->json('workflowStatus');
        $this->putJson('/real-estate/listings/workflow-statuses/'.$status['id'], ['code' => 'legal_review', 'name' => 'Legal Check', 'position' => 14, 'active' => true, 'expected_version' => 1])->assertOk()->assertJsonPath('workflowStatus.version', 2);
        $this->putJson('/real-estate/listings/workflow-statuses/'.$status['id'], ['code' => 'legal_review', 'name' => 'Stale', 'position' => 14, 'active' => true, 'expected_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->postJson('/real-estate/listings/workflow-statuses', ['code' => 'draft', 'name' => 'New Draft', 'position' => 0, 'active' => true])->assertCreated()->assertJsonPath('workflowStatus.version', 2);
        $this->postJson('/real-estate/listings/workflow-statuses', ['code' => 'draft', 'name' => 'Unsafe Retry', 'position' => 0, 'active' => true])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseMissing('listing_workflow_statuses', ['organization_id' => $other->id, 'code' => 'legal_review']);
    }
}
