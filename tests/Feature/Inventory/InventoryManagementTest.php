<?php

namespace Tests\Feature\Inventory;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_can_create_a_property_and_unit_in_its_organization(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $this->actingAs($manager)->post(route('inventory.properties.store'), ['name' => 'Harbor View', 'type' => 'residential'])->assertRedirect();
        $propertyId = Property::where('organization_id', $organization->id)->value('id');
        $this->actingAs($manager)->post(route('inventory.units.store'), ['property_id' => $propertyId, 'number' => 'A-101', 'type' => 'apartment', 'status' => 'available'])->assertRedirect();
        $this->assertDatabaseHas('units', ['organization_id' => $organization->id, 'number' => 'A-101']);
    }

    public function test_duplicate_property_name_returns_a_field_error_within_the_organization(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        Property::create(['organization_id' => $organization->id, 'name' => 'Opus', 'type' => 'commercial']);

        $this->actingAs($manager)->post(route('inventory.properties.store'), ['name' => 'Opus', 'type' => 'commercial'])
            ->assertRedirect()->assertSessionHasErrors('name');
        $this->assertDatabaseCount('properties', 1);

        $other = Organization::factory()->create();
        $otherManager = User::factory()->create(['current_organization_id' => $other->id]);
        $other->users()->attach($otherManager, ['role' => OrganizationRole::Manager->value]);
        $this->actingAs($otherManager)->post(route('inventory.properties.store'), ['name' => 'Opus', 'type' => 'commercial'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('properties', 2);
    }

    public function test_a_manager_can_add_a_building_and_change_its_tenant_unit_status(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Skyline', 'type' => 'commercial']);
        $unit = Unit::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'number' => '4A', 'type' => 'office']);

        $this->actingAs($manager)->post(route('inventory.buildings.store'), ['property_id' => $property->id, 'name' => 'Tower A', 'floors' => 20])->assertRedirect();
        $this->actingAs($manager)->put(route('inventory.units.status.update', $unit), ['status' => 'reserved'])->assertRedirect();

        $this->assertDatabaseHas('buildings', ['organization_id' => $organization->id, 'name' => 'Tower A']);
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'reserved']);
    }

    public function test_a_manager_can_assign_tenant_owners_without_exceeding_full_ownership(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Palm', 'type' => 'residential']);
        $firstOwner = Owner::create(['organization_id' => $organization->id, 'name' => 'Owner One']);
        $secondOwner = Owner::create(['organization_id' => $organization->id, 'name' => 'Owner Two']);

        $this->actingAs($manager)->put(route('inventory.properties.owners.assign', $property), ['owner_id' => $firstOwner->id, 'ownership_share' => 60])->assertRedirect();
        $this->actingAs($manager)->put(route('inventory.properties.owners.assign', $property), ['owner_id' => $secondOwner->id, 'ownership_share' => 41])->assertUnprocessable();

        $this->assertDatabaseHas('property_owner', ['property_id' => $property->id, 'owner_id' => $firstOwner->id, 'ownership_share' => 60]);
        $this->assertDatabaseMissing('property_owner', ['property_id' => $property->id, 'owner_id' => $secondOwner->id]);
    }
}
