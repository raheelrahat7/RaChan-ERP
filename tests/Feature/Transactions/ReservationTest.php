<?php

namespace Tests\Feature\Transactions;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_can_reserve_an_available_tenant_unit(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Cedar', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'number' => '12', 'type' => 'apartment']);
        $this->actingAs($manager)->post(route('reservations.store'), ['unit_id' => $unit->id, 'expires_at' => now()->addDay()->toDateTimeString()])->assertRedirect();
        $this->assertDatabaseHas('reservations', ['organization_id' => $organization->id, 'unit_id' => $unit->id, 'status' => 'active']);
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'reserved']);
    }
}
