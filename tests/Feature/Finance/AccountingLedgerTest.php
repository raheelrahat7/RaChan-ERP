<?php

namespace Tests\Feature\Finance;

use App\Domain\Accounting\Actions\AccountingLedger;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccountingLedgerTest extends TestCase
{
    use RefreshDatabase;

    public static function negativeJournalSides(): array
    {
        return [
            'negative credit' => ['10.00', '-1.00'],
            'negative debit' => ['-1.00', '10.00'],
        ];
    }

    #[DataProvider('negativeJournalSides')]
    public function test_domain_posting_rejects_negative_sides_even_when_totals_balance(string $debit, string $credit): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth()]);
        $cash = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1000', 'name' => 'Cash', 'type' => 'asset']);
        $equity = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '3000', 'name' => 'Equity', 'type' => 'equity']);

        try {
            app(AccountingLedger::class)->post($organization, $owner, today()->toDateString(), 'Invalid signed amounts', [
                ['ledger_account_id' => $cash->id, 'debit' => $debit, 'credit' => $credit],
                ['ledger_account_id' => $equity->id, 'debit' => $credit, 'credit' => $debit],
            ]);
            $this->fail('Negative journal amounts must be rejected by the domain action.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'accounting.journal.posted']);
    }

    public function test_balanced_posting_reversal_and_period_lock(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $date = today()->toDateString();

        $this->actingAs($owner)->post(route('accounting.periods.store'), ['name' => 'Current', 'starts_on' => today()->startOfMonth()->toDateString(), 'ends_on' => today()->endOfMonth()->toDateString()])->assertRedirect();
        $period = AccountingPeriod::sole();
        $this->actingAs($owner)->post(route('accounting.periods.store'), ['name' => 'Overlap', 'starts_on' => $date, 'ends_on' => today()->addMonth()->toDateString()])->assertStatus(422);
        $this->actingAs($owner)->post(route('accounting.accounts.store'), ['code' => '1000', 'name' => 'Cash', 'type' => 'asset'])->assertRedirect();
        $this->actingAs($owner)->post(route('accounting.accounts.store'), ['code' => '3000', 'name' => 'Equity', 'type' => 'equity'])->assertRedirect();
        $cash = LedgerAccount::where('code', '1000')->sole();
        $equity = LedgerAccount::where('code', '3000')->sole();
        $lines = [['ledger_account_id' => $cash->id, 'debit' => '150.00', 'credit' => '0'], ['ledger_account_id' => $equity->id, 'debit' => '0', 'credit' => '150.00']];

        $this->actingAs($owner)->post(route('accounting.journals.store'), ['posted_on' => $date, 'description' => 'Opening capital', 'lines' => $lines])->assertRedirect();
        $entry = JournalEntry::where('event', 'manual.posted')->sole();
        $this->assertSame('150.00', $entry->debit_total);
        $this->assertDatabaseCount('journal_lines', 2);
        $this->actingAs($owner)->post(route('accounting.journals.reverse', $entry), ['posted_on' => $date])->assertRedirect();
        $this->assertDatabaseHas('journal_entries', ['reversal_of_id' => $entry->id, 'event' => 'journal.reversed']);
        $this->actingAs($owner)->get(route('accounting.index'))->assertInertia(fn (Assert $page) => $page->component('finance/Accounting')->where('accounts.0.debit', '150.00')->where('accounts.0.credit', '150.00'));
        $this->actingAs($owner)->post(route('accounting.journals.reverse', $entry), ['posted_on' => $date])->assertStatus(422);
        $this->actingAs($owner)->post(route('accounting.periods.close', $period))->assertRedirect();
        $this->actingAs($owner)->post(route('accounting.journals.store'), ['posted_on' => $date, 'description' => 'Late entry', 'lines' => $lines])->assertStatus(422);
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_cross_organization_accounts_and_periods_are_rejected(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $foreign = LedgerAccount::create(['organization_id' => $other->id, 'code' => '1000', 'name' => 'Foreign', 'type' => 'asset']);
        $local = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '2000', 'name' => 'Local', 'type' => 'liability']);
        $period = AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth()]);

        $this->actingAs($owner)->put(route('accounting.accounts.update', $foreign), ['name' => 'Changed', 'is_active' => false])->assertNotFound();
        $this->actingAs($owner)->post(route('accounting.journals.store'), ['posted_on' => today()->toDateString(), 'description' => 'Invalid', 'lines' => [['ledger_account_id' => $foreign->id, 'debit' => '10', 'credit' => '0'], ['ledger_account_id' => $local->id, 'debit' => '0', 'credit' => '10']]])->assertNotFound();
        $this->assertDatabaseCount('journal_lines', 0);

        $member = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($member, ['role' => OrganizationRole::Member->value]);
        $this->actingAs($member)->post(route('accounting.periods.close', $period))->assertForbidden();
        $this->actingAs($member)->post(route('accounting.accounts.store'), ['code' => '5000', 'name' => 'Expense', 'type' => 'expense'])->assertForbidden();
    }

    public function test_unbalanced_journal_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth()]);
        $cash = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1000', 'name' => 'Cash', 'type' => 'asset']);
        $equity = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '3000', 'name' => 'Equity', 'type' => 'equity']);

        $this->actingAs($owner)->post(route('accounting.journals.store'), ['posted_on' => today()->toDateString(), 'description' => 'Invalid', 'lines' => [['ledger_account_id' => $cash->id, 'debit' => '10', 'credit' => '0'], ['ledger_account_id' => $equity->id, 'debit' => '0', 'credit' => '9']]])->assertStatus(422);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_close_blocks_ledger_warnings_and_only_owner_can_reopen_without_resolving_them(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $administrator = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $organization->users()->attach($administrator, ['role' => OrganizationRole::Administrator->value]);
        $period = AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth()]);
        $legacy = JournalEntry::create(['organization_id' => $organization->id, 'accounting_period_id' => $period->id, 'reference' => 'LEGACY-BLOCKER', 'event' => 'legacy', 'posted_on' => today(), 'debit_total' => 100, 'credit_total' => 100, 'currency' => 'AED']);

        $this->actingAs($owner)->post(route('accounting.periods.close', $period))->assertStatus(422);
        $this->assertDatabaseHas('accounting_periods', ['id' => $period->id, 'status' => 'open']);
        $this->actingAs($owner)->get(route('accounting.index'))->assertInertia(fn (Assert $page) => $page->where('periods.0.has_ledger_blocker', true)->where('periods.0.ledger_blockers.0', 'Unallocated legacy journal summaries'));
        $legacy->delete();
        $this->actingAs($owner)->post(route('accounting.periods.close', $period))->assertRedirect();
        JournalEntry::create(['organization_id' => $organization->id, 'accounting_period_id' => $period->id, 'reference' => 'LEGACY-AFTER-CLOSE', 'event' => 'legacy', 'posted_on' => today(), 'debit_total' => 100, 'credit_total' => 100, 'currency' => 'AED']);

        $this->actingAs($administrator)->post(route('accounting.periods.reopen', $period))->assertForbidden();
        $this->actingAs($owner)->post(route('accounting.periods.reopen', $period))->assertRedirect();
        $this->assertDatabaseHas('accounting_periods', ['id' => $period->id, 'status' => 'open', 'closed_by' => null, 'closed_at' => null]);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'actor_id' => $owner->id, 'event' => 'accounting.period.reopened']);
        $this->actingAs($owner)->post(route('accounting.periods.reopen', $period))->assertStatus(422);
    }

    public function test_closed_period_blocks_legacy_invoice_posting(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth(), 'status' => 'closed']);
        $invoice = Invoice::create(['organization_id' => $organization->id, 'reference' => 'INV-LOCKED', 'due_on' => today(), 'subtotal' => 100, 'total' => 100, 'accounting_treatment' => 'revenue']);

        $this->actingAs($owner)->post(route('invoices.post', $invoice))->assertStatus(422);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_trial_balance_csv_matches_current_organization_and_escapes_spreadsheet_formulas(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth()]);
        LedgerAccount::create(['organization_id' => $other->id, 'code' => '9999', 'name' => 'Foreign account', 'type' => 'asset']);
        $cash = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1000', 'name' => '=SUM(1,1)', 'type' => 'asset']);
        $equity = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '3000', 'name' => 'Equity', 'type' => 'equity']);
        $this->actingAs($manager)->post(route('accounting.journals.store'), [
            'posted_on' => today()->toDateString(), 'description' => 'Capital',
            'lines' => [
                ['ledger_account_id' => $cash->id, 'debit' => '25.00', 'credit' => '0'],
                ['ledger_account_id' => $equity->id, 'debit' => '0', 'credit' => '25.00'],
            ],
        ])->assertRedirect();

        $csv = $this->actingAs($manager)->get(route('accounting.trial-balance.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString("1000,\"'=SUM(1,1)\",asset,25.00,0.00,Active", $csv);
        $this->assertStringContainsString('3000,Equity,equity,0.00,25.00,Active', $csv);
        $this->assertStringNotContainsString('Foreign account', $csv);

        $outsider = User::factory()->create(['current_organization_id' => $organization->id]);
        $this->actingAs($outsider)->get(route('accounting.trial-balance.export'))->assertForbidden();
    }

    public function test_trial_balance_as_of_date_matches_csv_and_excludes_later_journals(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => today()->subDay(), 'ends_on' => today()->addDay()]);
        $cash = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1000', 'name' => 'Cash', 'type' => 'asset']);
        $equity = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '3000', 'name' => 'Equity', 'type' => 'equity']);
        foreach ([['date' => today()->subDay()->toDateString(), 'amount' => '10'], ['date' => today()->toDateString(), 'amount' => '5']] as $item) {
            $this->actingAs($manager)->post(route('accounting.journals.store'), ['posted_on' => $item['date'], 'description' => 'Capital', 'lines' => [
                ['ledger_account_id' => $cash->id, 'debit' => $item['amount'], 'credit' => '0'],
                ['ledger_account_id' => $equity->id, 'debit' => '0', 'credit' => $item['amount']],
            ]])->assertRedirect();
        }
        $asOf = today()->subDay()->toDateString();
        $this->actingAs($manager)->get(route('accounting.index', ['as_of' => $asOf]))
            ->assertInertia(fn (Assert $page) => $page->component('finance/Accounting')->where('asOf', $asOf)->where('accounts.0.debit', '10.00')->where('accounts.1.credit', '10.00'));
        $csv = $this->actingAs($manager)->get(route('accounting.trial-balance.export', ['as_of' => $asOf]))->assertOk()->streamedContent();
        $this->assertStringContainsString("\"As of\",{$asOf}", $csv);
        $this->assertStringContainsString('1000,Cash,asset,10.00,0.00,Active', $csv);
        $this->assertStringNotContainsString('15.00', $csv);
        $this->actingAs($manager)->get(route('accounting.index', ['as_of' => 'not-a-date']))->assertSessionHasErrors('as_of');
    }

    public function test_opening_balances_require_source_reference_and_post_balanced_aed_lines_in_an_open_period(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $period = AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Opening', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth()]);
        $bank = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1010', 'name' => 'Bank', 'type' => 'asset']);
        $equity = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '3000', 'name' => 'Equity', 'type' => 'equity']);
        $input = ['posted_on' => today()->toDateString(), 'source_reference' => 'Migration sheet 1', 'lines' => [
            ['ledger_account_id' => $bank->id, 'debit' => '500.00', 'credit' => '0'],
            ['ledger_account_id' => $equity->id, 'debit' => '0', 'credit' => '500.00'],
        ]];

        $this->actingAs($manager)->post(route('accounting.opening-balances.store'), [...$input, 'source_reference' => ''])->assertSessionHasErrors('source_reference');
        $this->actingAs($manager)->post(route('accounting.opening-balances.store'), [...$input, 'lines' => [
            $input['lines'][0], ['ledger_account_id' => $equity->id, 'debit' => '0', 'credit' => '499.00'],
        ]])->assertStatus(422);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->actingAs($manager)->post(route('accounting.opening-balances.store'), $input)->assertRedirect();
        $entry = JournalEntry::where('event', 'opening.balance')->sole();
        $this->assertSame('Migration sheet 1', $entry->source_reference);
        $this->assertSame('AED', $entry->currency);
        $this->assertSame('500.00', $entry->debit_total);
        $this->assertDatabaseCount('journal_lines', 2);
        $this->actingAs($manager)->post(route('accounting.opening-balances.store'), $input)->assertStatus(422);
        $this->assertDatabaseCount('journal_entries', 1);
        $period->update(['status' => 'closed']);
        $this->actingAs($manager)->post(route('accounting.opening-balances.store'), [...$input, 'source_reference' => 'Migration sheet 2'])->assertStatus(422);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_opening_balance_source_references_are_scoped_to_each_organization(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        foreach ([$first, $second] as $organization) {
            $manager = User::factory()->create(['current_organization_id' => $organization->id]);
            $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
            AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth()]);
            $asset = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1000', 'name' => 'Cash', 'type' => 'asset']);
            $equity = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '3000', 'name' => 'Equity', 'type' => 'equity']);
            $this->actingAs($manager)->post(route('accounting.opening-balances.store'), ['posted_on' => today()->toDateString(), 'source_reference' => 'Shared source', 'lines' => [
                ['ledger_account_id' => $asset->id, 'debit' => '10', 'credit' => '0'],
                ['ledger_account_id' => $equity->id, 'debit' => '0', 'credit' => '10'],
            ]])->assertRedirect();
        }
        $this->assertDatabaseCount('journal_entries', 2);
    }
}
