<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Broker;
use App\Models\CommissionAllocation;
use App\Models\CommissionTransaction;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CommissionAllocationTest extends TestCase
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

    private function commission(Organization $org): CommissionTransaction
    {
        $broker = Broker::create(['organization_id' => $org->id, 'name' => 'Agent']);

        return CommissionTransaction::create(['organization_id' => $org->id, 'broker_id' => $broker->id, 'base_amount' => '100000.00', 'commission_amount' => '6000.00', 'currency' => 'AED', 'payable_on' => today()->toDateString()]);
    }

    public function test_exact_split_requires_a_different_owner_and_populates_dashboard_after_approval(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $otherOwner = $this->member($org, OrganizationRole::Owner);
        $commission = $this->commission($org);
        $amounts = ['net_company' => '2500.00', 'agent_payable' => '3000.00', 'co_broker' => '400.00', 'referral' => '100.00'];

        $this->actingAs($owner)->post(route('real-estate.brokerage.allocations.submit', $commission), [...$amounts, 'referral' => '99.99'])->assertSessionHasErrors('net_company');
        $this->assertDatabaseCount('commission_allocations', 0);
        $this->post(route('real-estate.brokerage.allocations.submit', $commission), $amounts)->assertRedirect();
        $allocation = CommissionAllocation::sole();
        $this->post(route('real-estate.brokerage.allocations.approve', $allocation))->assertSessionHasErrors('allocation');
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('commission_split', null)->etc());

        $this->actingAs($otherOwner)->get(route('approvals.index'))->assertInertia(fn (Assert $page) => $page->where('count', 1)->etc());
        $this->post(route('real-estate.brokerage.allocations.approve', $allocation))->assertRedirect();
        $this->assertDatabaseHas('commission_allocations', ['id' => $allocation->id, 'status' => 'approved', 'approved_by' => $otherOwner->id]);
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('commission_split.net_company', 2500)->where('commission_split.agent_payable', 3000)->where('commission_split.co_broker', 400)->where('commission_split.referral', 100)->etc());
        $this->commission($org);
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('commission_split', null)->etc());
        $this->post(route('real-estate.brokerage.allocations.submit', $commission), $amounts)->assertSessionHasErrors('commission');
    }

    public function test_rejection_can_be_revised_but_other_organizations_cannot_review(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $reviewer = $this->member($org, OrganizationRole::Owner);
        $outsider = $this->member(Organization::factory()->create(), OrganizationRole::Owner);
        $commission = $this->commission($org);
        $amounts = ['net_company' => '6000.00', 'agent_payable' => '0', 'co_broker' => '0', 'referral' => '0'];
        $this->actingAs($owner)->post(route('real-estate.brokerage.allocations.submit', $commission), $amounts)->assertRedirect();
        $allocation = CommissionAllocation::sole();
        $this->actingAs($outsider)->post(route('real-estate.brokerage.allocations.approve', $allocation))->assertNotFound();
        $this->actingAs($reviewer)->post(route('real-estate.brokerage.allocations.reject', $allocation), ['reason' => 'Adjust the agent amount'])->assertRedirect();
        $this->actingAs($owner)->post(route('real-estate.brokerage.allocations.submit', $commission), ['net_company' => '5000.00', 'agent_payable' => '1000.00', 'co_broker' => '0', 'referral' => '0'])->assertRedirect();
        $this->assertDatabaseHas('commission_allocations', ['id' => $allocation->id, 'status' => 'submitted', 'net_company' => '5000.00']);
    }
}
