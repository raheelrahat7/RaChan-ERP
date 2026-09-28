<?php

namespace Tests\Feature\Finance;

use App\Domain\Accounting\Actions\AccountingLedger;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Finance\Models\OperatingBudget;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperatingBudgetTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private User $owner;

    private LedgerAccount $expense;

    private LedgerAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 27)->startOfDay());
        $this->organization = Organization::factory()->create();
        $this->manager = $this->user(OrganizationRole::Manager);
        $this->owner = $this->user(OrganizationRole::Owner);
        $this->expense = LedgerAccount::create(['organization_id' => $this->organization->id, 'code' => '5000', 'name' => 'Office expense', 'type' => 'expense']);
        $this->cash = LedgerAccount::create(['organization_id' => $this->organization->id, 'code' => '1000', 'name' => 'Cash', 'type' => 'asset']);
    }

    public function test_rejection_returns_an_editable_copy_and_approval_keeps_the_rejected_snapshot(): void
    {
        $this->actingAs($this->manager)->post(route('accounting.budgets.store'), ['year' => 2026, 'effective_from' => '2026-10-01', 'reason' => 'Initial operating plan'])->assertRedirect();
        $first = OperatingBudget::sole();
        $this->saveLine($first, '100.00');
        $this->actingAs($this->manager)->post(route('accounting.budgets.submit', $first))->assertRedirect();
        $this->actingAs($this->manager)->get(route('accounting.budgets', ['year' => 2026]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canReviewPending', false)
            ->where('report.pending.status', 'submitted'));
        $this->actingAs($this->owner)->get(route('accounting.budgets', ['year' => 2026]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canReviewPending', true)
            ->where('report.pending.submitter_name', $this->manager->name));

        $this->actingAs($this->manager)->post(route('accounting.budgets.approve', $first))->assertSessionHasErrors('budget');
        $this->actingAs($this->owner)->post(route('accounting.budgets.reject', $first), ['reason' => 'Reduce discretionary spend'])->assertRedirect();

        $first->refresh();
        $draft = OperatingBudget::where('status', 'draft')->sole();
        $this->assertSame('rejected', $first->status);
        $this->assertSame('Reduce discretionary spend', $first->rejection_reason);
        $this->assertSame(2, $draft->version);
        $this->assertSame('100.00', $draft->lines()->where('month', 10)->sole()->amount);

        $this->saveLine($draft, '80.00');
        $this->actingAs($this->manager)->post(route('accounting.budgets.submit', $draft))->assertRedirect();
        $this->actingAs($this->owner)->post(route('accounting.budgets.approve', $draft))->assertRedirect();
        $this->assertSame('approved', $draft->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance.operating_budget.rejected', 'subject_id' => $first->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance.operating_budget.approved', 'subject_id' => $draft->id]);

        $this->actingAs($this->manager)->get(route('accounting.budgets', ['year' => 2026, 'through_month' => 11]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('finance/OperatingBudgets')
            ->where('report.rows.0.months.10.budget', '80.00')
            ->where('report.versions.0.status', 'rejected')
            ->where('report.versions.1.status', 'approved'));
    }

    public function test_revisions_copy_the_active_plan_and_closed_periods_cannot_be_replaced(): void
    {
        $approved = $this->approveBudget('2026-10-01', 'Initial approved plan', '100.00');

        $this->actingAs($this->manager)->post(route('accounting.budgets.store'), ['year' => 2026, 'effective_from' => '2026-11-01', 'reason' => 'November reforecast'])->assertRedirect();
        $revision = OperatingBudget::where('status', 'draft')->sole();
        $this->assertSame($approved->id, $revision->supersedes_id);
        $this->assertSame('100.00', $revision->lines()->where('month', 10)->sole()->amount);
        $this->saveLine($revision, '90.00');

        $this->actingAs($this->manager)->put(route('accounting.budgets.rebase', $revision), ['effective_from' => '2026-12-01'])->assertRedirect();
        $this->assertSame('100.00', $revision->fresh()->lines()->where('month', 10)->sole()->amount);
        $this->assertSame('2026-12-01', $revision->fresh()->effective_from->toDateString());

        AccountingPeriod::create(['organization_id' => $this->organization->id, 'name' => 'October', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'status' => 'closed']);
        $this->actingAs($this->manager)->put(route('accounting.budgets.rebase', $revision), ['effective_from' => '2026-10-01'])->assertRedirect();

        $this->actingAs($this->manager)->post(route('accounting.budgets.submit', $revision))->assertRedirect();
        $this->actingAs($this->owner)->post(route('accounting.budgets.approve', $revision))->assertSessionHasErrors('budget');
        $this->assertSame('submitted', $revision->fresh()->status);
    }

    public function test_report_uses_detailed_posted_aed_actuals_including_reversals_and_exports_them(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 1)->startOfDay());
        $this->approveBudget('2026-09-01', 'September operating plan', '75.00');
        $this->travelTo(now()->setDate(2026, 9, 27)->startOfDay());
        $period = AccountingPeriod::create(['organization_id' => $this->organization->id, 'name' => 'September', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
        $entry = app(AccountingLedger::class)->post($this->organization, $this->owner, '2026-09-10', 'Expense payment', [
            ['ledger_account_id' => $this->expense->id, 'debit' => '100.00', 'credit' => '0'],
            ['ledger_account_id' => $this->cash->id, 'debit' => '0', 'credit' => '100.00'],
        ]);
        app(AccountingLedger::class)->reverse($this->organization, $this->owner, $entry, '2026-09-20');
        app(AccountingLedger::class)->post($this->organization, $this->owner, '2026-09-21', 'Expense payment', [
            ['ledger_account_id' => $this->expense->id, 'debit' => '80.00', 'credit' => '0'],
            ['ledger_account_id' => $this->cash->id, 'debit' => '0', 'credit' => '80.00'],
        ]);
        JournalEntry::create(['organization_id' => $this->organization->id, 'accounting_period_id' => $period->id, 'reference' => 'LEGACY-SUMMARY', 'event' => 'legacy', 'posted_on' => '2026-09-22', 'debit_total' => 500, 'credit_total' => 500, 'currency' => 'AED']);

        $this->actingAs($this->manager)->get(route('accounting.budgets', ['year' => 2026, 'through_month' => 9]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.rows.0.actual_to_date', '80.00')
            ->where('report.rows.0.budget_to_date', '75.00')
            ->where('report.rows.0.variance_to_date', '5.00'));

        $csv = $this->actingAs($this->manager)->get(route('accounting.budgets.export', ['year' => 2026, 'through_month' => 9]))->assertOk()->streamedContent();
        $this->assertStringContainsString('75.00,80.00,-5.00', $csv);
        $this->assertStringNotContainsString('500.00', $csv);
    }

    private function approveBudget(string $effectiveFrom, string $reason, string $amount): OperatingBudget
    {
        $this->actingAs($this->manager)->post(route('accounting.budgets.store'), ['year' => 2026, 'effective_from' => $effectiveFrom, 'reason' => $reason])->assertRedirect();
        $budget = OperatingBudget::where('status', 'draft')->sole();
        $this->saveLine($budget, $amount);
        $this->actingAs($this->manager)->post(route('accounting.budgets.submit', $budget))->assertRedirect();
        $this->actingAs($this->owner)->post(route('accounting.budgets.approve', $budget))->assertRedirect();

        return $budget->fresh();
    }

    private function saveLine(OperatingBudget $budget, string $amount): void
    {
        $this->actingAs($this->manager)->put(route('accounting.budgets.accounts.save', $budget), [
            'account_id' => $this->expense->id,
            'monthly_amounts' => array_fill(0, 12, $amount),
        ])->assertRedirect();
    }

    private function user(OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->organization->users()->attach($user, ['role' => $role->value]);

        return $user;
    }
}
