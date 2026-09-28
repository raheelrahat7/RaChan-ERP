<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomeDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function member(Organization $organization, OrganizationRole $role = OrganizationRole::Owner): User
    {
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function test_empty_organization_has_complete_shape_and_honest_nulls(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAs($this->member($organization))->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('filters.period', 'month')
            ->where('filters.purpose', 'all')
            ->where('filters.company_id', null)
            ->where('filters.options.companies', [])
            ->where('kpis.revenue.value', 0)
            ->where('kpis.net_profit.value', 0)
            ->where('kpis.cash_balance.change_7d_pct', null)
            ->where('kpis.pending_approvals.count', 0)
            ->where('kpis.overdue_tasks.count', 0)
            ->where('commission_split', null)
            ->where('top_agents', [])
            ->where('cost_centres', [])
            ->where('lead_pipeline.stages.0.count', 0)
            ->has('trend.months', 12)
            ->has('trend.sales_value', 12)
            ->etc());
    }

    public function test_posted_aed_income_and_expense_use_selected_period_and_organization(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $actor = $this->member($organization);
        $this->postJournal($organization, now()->toDateString(), 100, 30);
        $this->postJournal($other, now()->toDateString(), 900, 0);
        $this->postJournal($organization, now()->subMonth()->toDateString(), 25, 5);

        $this->actingAs($actor)->get(route('dashboard', ['period' => 'month']))->assertInertia(fn (Assert $page) => $page
            ->where('kpis.revenue.value', 100)
            ->where('kpis.expenses.value', 30)
            ->where('kpis.net_profit.value', 70)
            ->etc());
        $this->get(route('dashboard', ['period' => 'year']))->assertInertia(fn (Assert $page) => $page
            ->where('filters.period', 'year')->where('kpis.revenue.value', 125)->etc());
    }

    public function test_purpose_and_period_filters_are_validated_and_reflected(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAs($this->member($organization));
        foreach (['month', 'quarter', 'year'] as $period) {
            foreach (['all', 'sale', 'rent'] as $purpose) {
                $this->get(route('dashboard', ['period' => $period, 'purpose' => $purpose]))->assertInertia(fn (Assert $page) => $page
                    ->where('filters.period', $period)->where('filters.purpose', $purpose)->etc());
            }
        }
        $this->get(route('dashboard', ['period' => 'decade']))->assertSessionHasErrors(['period']);
        $this->get(route('dashboard', ['purpose' => 'both']))->assertSessionHasErrors(['purpose']);
    }

    public function test_lead_counts_respect_assignment_and_organization(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $owner = $this->member($organization);
        $viewer = $this->member($organization, OrganizationRole::Viewer);
        $this->member($other);
        CrmLead::create(['organization_id' => $organization->id, 'assigned_to' => $owner->id, 'first_name' => 'Owned', 'last_name' => 'Lead', 'source' => 'Referral']);
        CrmLead::create(['organization_id' => $organization->id, 'assigned_to' => $viewer->id, 'first_name' => 'Visible', 'last_name' => 'Lead', 'source' => 'Website']);
        CrmLead::create(['organization_id' => $other->id, 'first_name' => 'Foreign', 'last_name' => 'Lead', 'source' => 'Foreign']);

        $this->actingAs($viewer)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('counts.crm_open_leads', 1)
            ->where('lead_sources.0.source', 'Website')
            ->where('lead_pipeline.stages.0.count', 1)
            ->etc());
        $this->actingAs($owner)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('counts.crm_open_leads', 2)
            ->has('lead_sources', 2)
            ->etc());
    }

    private function postJournal(Organization $organization, string $date, int $revenue, int $expense): void
    {
        $income = DB::table('ledger_accounts')->where('organization_id', $organization->id)->where('code', '4000')->value('id')
            ?? DB::table('ledger_accounts')->insertGetId(['organization_id' => $organization->id, 'code' => '4000', 'name' => 'Revenue', 'type' => 'income', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $cost = DB::table('ledger_accounts')->where('organization_id', $organization->id)->where('code', '5000')->value('id')
            ?? DB::table('ledger_accounts')->insertGetId(['organization_id' => $organization->id, 'code' => '5000', 'name' => 'Expense', 'type' => 'expense', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $entry = DB::table('journal_entries')->insertGetId(['organization_id' => $organization->id, 'reference' => 'TEST-'.$organization->id.'-'.$date, 'event' => 'manual.posted', 'posted_on' => $date, 'debit_total' => $revenue + $expense, 'credit_total' => $revenue + $expense, 'currency' => 'AED', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('journal_lines')->insert([
            ['journal_entry_id' => $entry, 'ledger_account_id' => $income, 'debit' => 0, 'credit' => $revenue, 'created_at' => now(), 'updated_at' => now()],
            ['journal_entry_id' => $entry, 'ledger_account_id' => $cost, 'debit' => $expense, 'credit' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
