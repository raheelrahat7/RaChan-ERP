<?php

namespace Tests\Feature\RealEstate;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Listing;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PropertyPartiesPhase2eTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_developer_details_are_versioned_and_show_linked_records(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $this->actingAs($owner);

        $seller = $this->postJson(route('real-estate.people.store', 'owners'), [
            'name' => 'Seller One', 'payment_terms' => 'Monthly', 'commission_notes' => 'Two percent',
        ])->assertCreated()->assertJsonPath('record.version', 1)->assertJsonPath('record.permissions.edit', true)->json('record.id');
        $developer = $this->postJson(route('real-estate.people.store', 'developers'), [
            'name' => 'Coastal Homes', 'payment_terms' => 'Milestone',
        ])->assertCreated()->assertJsonPath('record.version', 1)->json('record.id');

        $property = Property::create(['organization_id' => $org->id, 'name' => 'Coastal One', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $org->id, 'property_id' => $property->id, 'number' => 'A1', 'type' => 'apartment']);
        Listing::create(['organization_id' => $org->id, 'unit_id' => $unit->id, 'reference' => 'COAST-A1', 'purpose' => 'sale', 'price' => 100000, 'owner_id' => $seller, 'developer_id' => $developer]);
        DB::table('offplan_projects')->insert(['organization_id' => $org->id, 'developer_id' => $developer,
            'code' => 'COAST', 'name' => 'Coastal Project', 'emirate' => 'Dubai', 'commission_rate' => 2,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson(route('real-estate.people.show', ['owners', $seller]))->assertOk()
            ->assertJsonPath('record.listings.0.reference', 'COAST-A1');
        $this->getJson(route('real-estate.people.show', ['developers', $developer]))->assertOk()
            ->assertJsonPath('record.listings.0.reference', 'COAST-A1')
            ->assertJsonPath('record.offplan_projects.0.code', 'COAST');
        $this->putJson(route('real-estate.people.update', ['owners', $seller]), [
            'expected_version' => 1, 'payment_terms' => 'Quarterly',
        ])->assertOk()->assertJsonPath('record.version', 2)->assertJsonPath('record.payment_terms', 'Quarterly');
        $this->putJson(route('real-estate.people.update', ['owners', $seller]), [
            'expected_version' => 1, 'payment_terms' => 'Stale',
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->getJson(route('real-estate.people.data', 'owners'))->assertOk()
            ->assertJsonPath('records.data.0.permissions.edit', true);
    }

    public function test_tenant_boundaries_and_invalid_developer_link(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $otherUser = User::factory()->create(['current_organization_id' => $other->id]);
        $other->users()->attach($otherUser, ['role' => OrganizationRole::Owner->value]);
        $foreignDeveloper = $this->actingAs($otherUser)->postJson(route('real-estate.people.store', 'developers'), ['name' => 'Foreign'])
            ->assertCreated()->json('record.id');
        $this->actingAs($owner)->getJson(route('real-estate.people.show', ['developers', $foreignDeveloper]))->assertNotFound();
        $this->putJson(route('real-estate.people.update', ['developers', $foreignDeveloper]), ['expected_version' => 1, 'name' => 'Wrong'])->assertNotFound();

        $property = Property::create(['organization_id' => $org->id, 'name' => 'Home', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $org->id, 'property_id' => $property->id, 'number' => 'A1', 'type' => 'apartment']);
        $this->postJson(route('real-estate.listings.store'), ['inventory_mode' => 'existing_unit', 'unit_id' => $unit->id,
            'purpose' => 'sale', 'price' => 100000, 'developer_id' => $foreignDeveloper])
            ->assertUnprocessable()->assertJsonValidationErrors('developer_id');
    }
}
