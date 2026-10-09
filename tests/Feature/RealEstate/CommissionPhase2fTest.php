<?php

namespace Tests\Feature\RealEstate;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Broker;
use App\Models\CommissionTransaction;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommissionPhase2fTest extends TestCase
{
    use RefreshDatabase;

    public function test_clawback_updates_version_and_team_net_contribution(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $reviewer = User::factory()->create(['current_organization_id' => $org->id]);
        $agent = User::factory()->create(['current_organization_id' => $org->id]);
        foreach ([$owner, $reviewer] as $user) {
            $org->users()->attach($user, ['role' => OrganizationRole::Owner->value]);
        }
        $org->users()->attach($agent, ['role' => OrganizationRole::Member->value]);
        $broker = Broker::create(['organization_id' => $org->id, 'user_id' => $agent->id, 'name' => 'Nadia']);
        $department = DB::table('crm_departments')->insertGetId(['organization_id' => $org->id, 'name' => 'Sales', 'created_at' => now(), 'updated_at' => now()]);
        $subdepartment = DB::table('crm_subdepartments')->insertGetId(['organization_id' => $org->id, 'department_id' => $department, 'name' => 'Dubai', 'created_at' => now(), 'updated_at' => now()]);
        $team = DB::table('crm_teams')->insertGetId(['organization_id' => $org->id, 'subdepartment_id' => $subdepartment, 'name' => 'Waterfront', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('crm_team_memberships')->insert(['organization_id' => $org->id, 'user_id' => $agent->id, 'team_id' => $team, 'created_at' => now(), 'updated_at' => now()]);
        $commission = CommissionTransaction::create(['organization_id' => $org->id, 'broker_id' => $broker->id, 'base_amount' => '100000.00', 'commission_amount' => '6000.00', 'currency' => 'AED']);

        $this->actingAs($owner)->post(route('real-estate.brokerage.allocations.submit', $commission), [
            'net_company' => '2500.00', 'agent_payable' => '3000.00', 'co_broker' => '400.00', 'referral' => '100.00',
        ])->assertRedirect();
        $allocation = DB::table('commission_allocations')->sole();
        $this->actingAs($reviewer)->post(route('real-estate.brokerage.allocations.approve', $allocation->id))->assertRedirect();

        $this->actingAs($owner)->postJson(route('real-estate.brokerage.clawbacks.store', $commission), [
            'expected_version' => 1, 'amount' => '500.25', 'reason' => 'Customer refund',
        ])->assertCreated()->assertJsonPath('clawback.version', 1)->assertJsonPath('commission.version', 2)
            ->assertJsonPath('commission.net_contribution', '1999.75');
        $this->getJson(route('real-estate.brokerage.data', ['team_id' => $team]))->assertOk()
            ->assertJsonPath('commissions.data.0.team_name', 'Waterfront')
            ->assertJsonPath('teamSummary.0.net_contribution', '1999.75');
        $this->postJson(route('real-estate.brokerage.clawbacks.store', $commission), [
            'expected_version' => 1, 'amount' => '1.00', 'reason' => 'Stale',
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->postJson(route('real-estate.brokerage.clawbacks.store', $commission), [
            'expected_version' => 2, 'amount' => '6000.00', 'reason' => 'Too large',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_foreign_commission_is_not_visible_or_mutable(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $broker = Broker::create(['organization_id' => $other->id, 'name' => 'Foreign']);
        $commission = CommissionTransaction::create(['organization_id' => $other->id, 'broker_id' => $broker->id, 'base_amount' => '1000.00', 'commission_amount' => '100.00', 'currency' => 'AED']);
        $this->actingAs($owner)->getJson(route('real-estate.brokerage.commissions.show', $commission))->assertNotFound();
        $this->postJson(route('real-estate.brokerage.clawbacks.store', $commission), [
            'expected_version' => 1, 'amount' => '50.00', 'reason' => 'Foreign',
        ])->assertNotFound();
    }
}
