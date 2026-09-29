<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Broker;
use App\Models\CommissionTransaction;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BrokerUserMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function member(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function test_owner_maps_broker_to_member_and_home_ranks_actual_commission(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $broker = Broker::create(['organization_id' => $org->id, 'name' => 'Broker A']);
        $unmapped = Broker::create(['organization_id' => $org->id, 'name' => 'Broker B']);
        CommissionTransaction::create(['organization_id' => $org->id, 'broker_id' => $broker->id, 'source_type' => 'lease',
            'source_id' => 1, 'base_amount' => 1000, 'commission_amount' => 100, 'currency' => 'AED', 'payable_on' => today()->toDateString()]);
        CommissionTransaction::create(['organization_id' => $org->id, 'broker_id' => $unmapped->id, 'source_type' => 'lease',
            'source_id' => 2, 'base_amount' => 2000, 'commission_amount' => 200, 'currency' => 'AED', 'payable_on' => today()->toDateString()]);

        $this->actingAs($owner)->put(route('real-estate.people.brokers.user', $broker->id), ['user_id' => $agent->id, 'reason' => 'Verified employee identity'])->assertRedirect();
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->has('top_agents', 1)->where('top_agents.0.user_id', $agent->id)->where('top_agents.0.commission', 100)
            ->where('top_agents.0.deals', 1)->etc());
        $this->put(route('real-estate.people.brokers.user', $unmapped->id), ['user_id' => $agent->id, 'reason' => 'Duplicate'])->assertSessionHasErrors(['user_id']);
    }

    public function test_foreign_user_and_non_owner_mapping_are_denied(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $member = $this->member($org, OrganizationRole::Member);
        $foreign = $this->member($other, OrganizationRole::Member);
        $broker = Broker::create(['organization_id' => $org->id, 'name' => 'Broker A']);

        $this->actingAs($member)->put(route('real-estate.people.brokers.user', $broker->id), ['user_id' => $member->id, 'reason' => 'Attempt'])->assertForbidden();
        $this->actingAs($owner)->put(route('real-estate.people.brokers.user', $broker->id), ['user_id' => $foreign->id, 'reason' => 'Wrong tenant'])->assertSessionHasErrors(['user_id']);
        $this->assertDatabaseHas('brokers', ['id' => $broker->id, 'user_id' => null]);
    }
}
