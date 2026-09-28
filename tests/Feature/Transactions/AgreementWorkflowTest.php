<?php

namespace Tests\Feature\Transactions;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Broker;
use App\Models\CommissionPlan;
use App\Models\Lease;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgreementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_can_activate_one_lease_for_a_reserved_unit(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Marina', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'number' => '501', 'type' => 'apartment', 'status' => 'reserved']);
        $reservation = Reservation::create(['organization_id' => $organization->id, 'unit_id' => $unit->id, 'reference' => 'RSV-TEST', 'expires_at' => now()->addDay()]);

        $this->actingAs($manager)->post(route('agreements.leases.store'), ['reservation_id' => $reservation->id, 'starts_on' => now()->toDateString(), 'ends_on' => now()->addYear()->toDateString(), 'rent_amount' => 120000])->assertRedirect();
        $lease = Lease::sole();
        $this->actingAs($manager)->post(route('agreements.leases.activate', $lease))->assertRedirect();
        $this->assertDatabaseHas('leases', ['id' => $lease->id, 'status' => 'active']);
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'leased']);
    }

    public function test_activation_calculates_the_selected_broker_commission(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Marina', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'number' => '502', 'type' => 'apartment', 'status' => 'reserved']);
        $reservation = Reservation::create(['organization_id' => $organization->id, 'unit_id' => $unit->id, 'reference' => 'RSV-COMMISSION', 'expires_at' => now()->addDay()]);
        $broker = Broker::create(['organization_id' => $organization->id, 'name' => 'Broker One']);
        $plan = CommissionPlan::create(['organization_id' => $organization->id, 'name' => 'Five percent', 'basis' => 'percentage', 'rate' => 5]);

        $this->actingAs($manager)->post(route('agreements.leases.store'), [
            'reservation_id' => $reservation->id,
            'broker_id' => $broker->id,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addYear()->toDateString(),
            'rent_amount' => 120000,
        ])->assertRedirect();

        $lease = Lease::sole();
        $this->actingAs($manager)->post(route('agreements.leases.activate', $lease), ['commission_plan_id' => $plan->id])->assertRedirect();

        $this->assertDatabaseHas('commission_transactions', [
            'organization_id' => $organization->id,
            'broker_id' => $broker->id,
            'commission_plan_id' => $plan->id,
            'base_amount' => 120000,
            'commission_amount' => 6000,
            'status' => 'calculated',
        ]);
    }

    public function test_a_tenant_lease_can_be_renewed_after_activation(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Marina', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'number' => '503', 'type' => 'apartment', 'status' => 'reserved']);
        $reservation = Reservation::create(['organization_id' => $organization->id, 'unit_id' => $unit->id, 'reference' => 'RSV-RENEWAL', 'expires_at' => now()->addDay()]);
        $tenant = Tenant::create(['organization_id' => $organization->id, 'name' => 'Tenant One']);

        $this->actingAs($manager)->post(route('agreements.leases.store'), [
            'reservation_id' => $reservation->id,
            'tenant_id' => $tenant->id,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addMonths(6)->toDateString(),
            'rent_amount' => 120000,
        ])->assertRedirect();
        $lease = Lease::sole();
        $this->actingAs($manager)->post(route('agreements.leases.activate', $lease))->assertRedirect();
        $newEndDate = now()->addYear()->toDateString();

        $this->actingAs($manager)->post(route('agreements.leases.renew', $lease), ['ends_on' => $newEndDate, 'rent_amount' => 132000])->assertRedirect();

        $lease->refresh();
        $this->assertSame($tenant->id, $lease->tenant_id);
        $this->assertSame($newEndDate, $lease->ends_on->toDateString());
        $this->assertSame('132000.00', $lease->rent_amount);
    }
}
