<?php

namespace Tests\Feature;

use App\Domain\Accounting\Actions\AccountingLedger;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountingDimensionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function member(Organization $organization, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function test_owner_creates_hierarchy_and_manual_postings_keep_dimensions_through_reversal(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->member($organization, OrganizationRole::Owner);
        $this->actingAs($owner)->post(route('accounting.dimensions.store', 'company'), ['code' => 'ACME', 'name' => 'Acme UAE'])->assertRedirect();
        $company = DB::table('accounting_companies')->sole();
        $this->post(route('accounting.dimensions.store', 'branch'), ['company_id' => $company->id, 'code' => 'DXB', 'name' => 'Dubai'])->assertRedirect();
        $branch = DB::table('accounting_branches')->sole();
        $this->post(route('accounting.dimensions.store', 'cost_centre'), ['branch_id' => $branch->id, 'code' => 'SALES', 'name' => 'Sales'])->assertRedirect();
        $centre = DB::table('accounting_cost_centres')->sole();
        $this->get(route('accounting.dimensions.index'))->assertInertia(fn (Assert $page) => $page
            ->where('companies.0.name', 'Acme UAE')->where('branches.0.company_id', $company->id)
            ->where('costCentres.0.branch_id', $branch->id)->etc());

        DB::table('accounting_periods')->insert(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => now()->startOfYear()->toDateString(), 'ends_on' => now()->endOfYear()->toDateString(), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $income = DB::table('ledger_accounts')->insertGetId(['organization_id' => $organization->id, 'code' => '4000', 'name' => 'Revenue', 'type' => 'income', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $cost = DB::table('ledger_accounts')->insertGetId(['organization_id' => $organization->id, 'code' => '5000', 'name' => 'Expense', 'type' => 'expense', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $lines = [
            ['ledger_account_id' => $cost, 'debit' => '100.00', 'credit' => '0.00', 'company_id' => $company->id, 'branch_id' => $branch->id, 'cost_centre_id' => $centre->id],
            ['ledger_account_id' => $income, 'debit' => '0.00', 'credit' => '100.00', 'company_id' => $company->id, 'branch_id' => $branch->id, 'cost_centre_id' => $centre->id],
        ];
        $this->post(route('accounting.journals.store'), ['posted_on' => now()->toDateString(), 'description' => 'Test posting', 'lines' => $lines])->assertRedirect();
        $entry = JournalEntry::sole();
        $this->assertSame(2, $entry->lines()->where('company_id', $company->id)->where('branch_id', $branch->id)->where('cost_centre_id', $centre->id)->count());
        $this->get(route('dashboard', ['company' => $company->id, 'branch' => $branch->id]))->assertInertia(fn (Assert $page) => $page
            ->where('filters.company_id', $company->id)->where('filters.branch_id', $branch->id)
            ->where('kpis.revenue.value', 100)->where('kpis.expenses.value', 100)
            ->where('cost_centres.2.cost_centre', 'Sales')->etc());

        app(AccountingLedger::class)->reverse($organization, $owner, $entry, now()->toDateString());
        $this->assertSame(4, DB::table('journal_lines')->where('company_id', $company->id)->where('branch_id', $branch->id)->where('cost_centre_id', $centre->id)->count());
    }

    public function test_foreign_hierarchy_and_unprivileged_creation_are_rejected(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $owner = $this->member($organization, OrganizationRole::Owner);
        $viewer = $this->member($organization, OrganizationRole::Viewer);
        $foreignCompany = DB::table('accounting_companies')->insertGetId(['organization_id' => $other->id, 'code' => 'OTHER', 'name' => 'Other', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($viewer)->post(route('accounting.dimensions.store', 'company'), ['code' => 'NO', 'name' => 'No'])->assertForbidden();
        $this->actingAs($owner)->post(route('accounting.dimensions.store', 'branch'), ['company_id' => $foreignCompany, 'code' => 'NO', 'name' => 'No'])->assertSessionHasErrors(['company_id']);
        $this->get(route('dashboard', ['company' => $foreignCompany]))->assertSessionHasErrors(['company']);
    }
}
