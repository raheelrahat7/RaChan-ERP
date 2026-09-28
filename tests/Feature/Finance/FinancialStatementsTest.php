<?php

namespace Tests\Feature\Finance;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinancialStatementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_statements_separate_prior_results_and_current_profit_and_balance(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Prior', 'starts_on' => today()->subMonths(2)->startOfMonth(), 'ends_on' => today()->subMonth()->endOfMonth(), 'status' => 'closed']);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth()]);
        $bank = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1000', 'name' => '=Bank', 'type' => 'asset']);
        $equity = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '3000', 'name' => 'Equity', 'type' => 'equity']);
        $income = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '4000', 'name' => 'Revenue', 'type' => 'income']);
        $expense = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '5000', 'name' => 'Expense', 'type' => 'expense']);
        $post = function (string $date, array $lines) use ($manager): void {
            $this->actingAs($manager)->post(route('accounting.journals.store'), ['posted_on' => $date, 'description' => 'Test', 'lines' => $lines])->assertRedirect();
        };
        $priorPeriod = AccountingPeriod::where('name', 'Prior')->sole();
        $priorPeriod->update(['status' => 'open']);
        $post(today()->subMonth()->toDateString(), [['ledger_account_id' => $bank->id, 'debit' => '100', 'credit' => '0'], ['ledger_account_id' => $income->id, 'debit' => '0', 'credit' => '100']]);
        $priorPeriod->update(['status' => 'closed']);
        $post(today()->toDateString(), [['ledger_account_id' => $bank->id, 'debit' => '50', 'credit' => '0'], ['ledger_account_id' => $income->id, 'debit' => '0', 'credit' => '50']]);
        $post(today()->toDateString(), [['ledger_account_id' => $expense->id, 'debit' => '20', 'credit' => '0'], ['ledger_account_id' => $bank->id, 'debit' => '0', 'credit' => '20']]);
        $post(today()->toDateString(), [['ledger_account_id' => $bank->id, 'debit' => '10', 'credit' => '0'], ['ledger_account_id' => $equity->id, 'debit' => '0', 'credit' => '10']]);

        $this->actingAs($manager)->get(route('accounting.statements', ['as_of' => today()->toDateString(), 'from' => today()->startOfMonth()->toDateString(), 'to' => today()->toDateString()]))
            ->assertInertia(fn (Assert $page) => $page->component('finance/FinancialStatements')
                ->where('balanceSheet.prior_results', '100.00')->where('balanceSheet.current_profit', '30.00')
                ->where('balanceSheet.total_assets', '140.00')->where('balanceSheet.total_equity', '140.00')
                ->where('balanceSheet.total_liabilities_and_equity', '140.00')
                ->where('profitAndLoss.total_income', '50.00')->where('profitAndLoss.total_expenses', '20.00')->where('profitAndLoss.profit', '30.00'));
        $balanceCsv = $this->actingAs($manager)->get(route('accounting.statements.balance-sheet.export', ['as_of' => today()->toDateString()]))->assertOk()->streamedContent();
        $this->assertStringContainsString("1000,'=Bank,140.00", $balanceCsv);
        $this->assertStringContainsString('"Prior accumulated results",100.00', $balanceCsv);
        $this->assertStringContainsString('"Current-period profit/(loss)",30.00', $balanceCsv);
        $profitCsv = $this->actingAs($manager)->get(route('accounting.statements.profit-and-loss.export', ['from' => today()->startOfMonth()->toDateString(), 'to' => today()->toDateString()]))->assertOk()->streamedContent();
        $this->assertStringContainsString('4000,Revenue,50.00', $profitCsv);
        $this->assertStringContainsString('5000,Expense,20.00', $profitCsv);
        $this->assertStringContainsString('Profit/(loss),30.00', $profitCsv);
    }

    public function test_statements_require_finance_access_and_show_missing_period_state(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $this->actingAs($manager)->get(route('accounting.statements'))->assertInertia(fn (Assert $page) => $page->where('balanceSheet', null));
        $this->actingAs($manager)->get(route('accounting.statements.balance-sheet.export', ['as_of' => today()->toDateString()]))->assertStatus(422);
        $this->actingAs($manager)->get(route('accounting.statements', ['from' => '2026-09-15', 'to' => '2026-09-14']))->assertSessionHasErrors('to');
        $outsider = User::factory()->create(['current_organization_id' => $organization->id]);
        $this->actingAs($outsider)->get(route('accounting.statements'))->assertForbidden();
        $this->actingAs($outsider)->get(route('accounting.statements.profit-and-loss.export', ['from' => today()->toDateString(), 'to' => today()->toDateString()]))->assertForbidden();
    }
}
