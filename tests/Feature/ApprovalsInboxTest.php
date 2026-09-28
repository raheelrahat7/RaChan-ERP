<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApprovalsInboxTest extends TestCase
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

    public function test_owner_and_manager_only_see_approvals_they_can_act_on(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $manager = $this->member($org, OrganizationRole::Manager);
        $viewer = $this->member($org, OrganizationRole::Viewer);
        $this->insertBudget($org, $manager);
        $this->insertBudget($other, $this->member($other, OrganizationRole::Manager));
        DB::table('purchase_requests')->insert(['organization_id' => $org->id, 'requested_by' => $viewer->id,
            'reference' => 'PR-APPROVAL', 'purpose' => 'Test', 'status' => 'submitted', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner)->get(route('approvals.index'))->assertInertia(fn (Assert $page) => $page
            ->where('count', 2)->has('items', 2)->where('items.0.status', 'submitted')->etc());
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('counts.approvals_pending', 2)->where('kpis.pending_approvals.count', 2)->etc());
        $this->actingAs($manager)->get(route('approvals.index'))->assertInertia(fn (Assert $page) => $page
            ->where('count', 1)->where('items.0.module', 'Procurement')->etc());
        $this->actingAs($viewer)->get(route('approvals.index'))->assertInertia(fn (Assert $page) => $page
            ->where('count', 0)->where('items', [])->etc());
    }

    private function insertBudget(Organization $org, User $submitter): void
    {
        DB::table('operating_budgets')->insert([
            'organization_id' => $org->id, 'year' => (int) now()->year, 'version' => 1,
            'status' => 'submitted', 'currency' => 'AED', 'reason' => 'Annual plan',
            'created_by' => $submitter->id, 'submitted_by' => $submitter->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
