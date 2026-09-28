<?php

namespace Tests\Feature\Finance;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\BankSettlement;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Invoice;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_rows_match_clearing_entries_once_without_new_journals(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $invoice = Invoice::create(['organization_id' => $organization->id, 'reference' => 'INV-BANK', 'accounting_treatment' => 'revenue', 'due_on' => today(), 'subtotal' => 100, 'total' => 100]);
        $this->actingAs($manager)->post(route('invoices.post', $invoice))->assertRedirect();
        $this->actingAs($manager)->post(route('invoices.pay', $invoice), ['amount' => 100])->assertRedirect();
        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Vendor']);
        $bill = VendorBill::create(['organization_id' => $organization->id, 'vendor_id' => $vendor->id, 'reference' => 'BIL-BANK', 'description' => 'Service', 'accounting_treatment' => 'operating_expense', 'bill_date' => today(), 'total' => 50]);
        $this->actingAs($manager)->post(route('vendor-bills.post', $bill))->assertRedirect();
        $this->actingAs($manager)->post(route('vendor-bills.pay', $bill), ['amount' => 50])->assertRedirect();

        $this->actingAs($manager)->post(route('bank-reconciliation.accounts.store'), ['name' => 'Operating Bank'])->assertRedirect();
        $bankAccount = BankAccount::sole();
        $this->actingAs($manager)->get(route('bank-reconciliation.index'))->assertInertia(fn (Assert $page) => $page->component('finance/BankReconciliation')->has('accounts', 1));
        $csv = "transaction_id,date,description,amount\nDEP-1,".today()->toDateString().",Customer receipt,100.00\nOUT-1,".today()->toDateString().",Vendor payment,-50.00\n";
        $this->actingAs($manager)->post(route('bank-reconciliation.import'), ['bank_account_id' => $bankAccount->id, 'file' => UploadedFile::fake()->createWithContent('statement.csv', $csv)])->assertRedirect();
        $this->assertDatabaseCount('bank_statement_lines', 2);
        $this->actingAs($manager)->post(route('bank-reconciliation.import'), ['bank_account_id' => $bankAccount->id, 'file' => UploadedFile::fake()->createWithContent('statement.csv', $csv)])->assertRedirect();
        $this->assertDatabaseCount('bank_statement_lines', 2);

        $deposit = BankStatementLine::where('external_id', 'DEP-1')->sole();
        $withdrawal = BankStatementLine::where('external_id', 'OUT-1')->sole();
        $receiptLine = JournalLine::whereHas('account', fn ($query) => $query->where('code', '1150'))->sole();
        $paymentLine = JournalLine::whereHas('account', fn ($query) => $query->where('code', '1190'))->sole();
        $journalCount = JournalLine::count();
        $this->actingAs($manager)->post(route('bank-reconciliation.lines.match', $deposit), ['journal_line_id' => $paymentLine->id])->assertStatus(422);
        $this->actingAs($manager)->post(route('bank-reconciliation.lines.match', $deposit), ['journal_line_id' => $receiptLine->id])->assertRedirect();
        $this->actingAs($manager)->post(route('bank-reconciliation.lines.match', $withdrawal), ['journal_line_id' => $paymentLine->id])->assertRedirect();
        $this->assertSame($journalCount, JournalLine::count());
        $this->actingAs($manager)->post(route('bank-reconciliation.lines.unmatch', $deposit))->assertRedirect();
        $this->assertNull($deposit->fresh()->matched_journal_line_id);
    }

    public function test_foreign_accounts_and_invalid_csv_are_rejected(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $foreign = BankAccount::create(['organization_id' => $other->id, 'name' => 'Foreign', 'currency' => 'AED']);
        $this->actingAs($manager)->post(route('bank-reconciliation.import'), ['bank_account_id' => $foreign->id, 'file' => UploadedFile::fake()->createWithContent('statement.csv', "transaction_id,date,description,amount\n1,2026-09-15,Test,10.00\n")])->assertNotFound();
        $local = BankAccount::create(['organization_id' => $organization->id, 'name' => 'Local', 'currency' => 'AED']);
        $this->actingAs($manager)->post(route('bank-reconciliation.import'), ['bank_account_id' => $local->id, 'file' => UploadedFile::fake()->createWithContent('bad.csv', "wrong,header\n1,2\n")])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('bank_statement_lines', 0);
    }

    public function test_statement_filters_keep_bank_and_match_state_scoped_to_the_organization(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $bank = BankAccount::create(['organization_id' => $organization->id, 'name' => 'Main', 'currency' => 'AED']);
        $second = BankAccount::create(['organization_id' => $organization->id, 'name' => 'Savings', 'currency' => 'AED']);
        $foreign = BankAccount::create(['organization_id' => $other->id, 'name' => 'Foreign', 'currency' => 'AED']);
        foreach ([$bank, $second, $foreign] as $account) {
            BankStatementLine::create(['organization_id' => $account->organization_id, 'bank_account_id' => $account->id, 'imported_by' => $manager->id, 'import_batch' => (string) Str::uuid(), 'external_id' => 'ROW-'.$account->id, 'occurred_on' => today(), 'description' => $account->name, 'amount' => '10.00']);
        }

        $this->actingAs($manager)->get(route('bank-reconciliation.index', ['bank_account_id' => $bank->id, 'status' => 'unmatched']))
            ->assertInertia(fn (Assert $page) => $page->component('finance/BankReconciliation')->has('lines.data', 1)->where('lines.data.0.external_id', 'ROW-'.$bank->id)->where('filters.status', 'unmatched'));
        $this->actingAs($manager)->get(route('bank-reconciliation.index', ['status' => 'matched']))
            ->assertInertia(fn (Assert $page) => $page->has('lines.data', 0));
        $this->actingAs($manager)->get(route('bank-reconciliation.index', ['bank_account_id' => $foreign->id]))->assertSessionHasErrors('bank_account_id');
    }

    public function test_manager_approves_bank_settlement_and_reverses_it_in_an_open_period(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $bankAccount = BankAccount::create(['organization_id' => $organization->id, 'name' => 'Operating', 'currency' => 'AED']);
        $bankLedger = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1010', 'name' => 'Operating Bank', 'type' => 'asset']);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth()]);
        $this->actingAs($manager)->put(route('bank-reconciliation.accounts.ledger-account', $bankAccount), ['ledger_account_id' => $bankLedger->id])->assertRedirect();
        $invoice = Invoice::create(['organization_id' => $organization->id, 'reference' => 'INV-SETTLE', 'accounting_treatment' => 'revenue', 'due_on' => today(), 'subtotal' => 100, 'total' => 100]);
        $this->actingAs($manager)->post(route('invoices.post', $invoice))->assertRedirect();
        $this->actingAs($manager)->post(route('invoices.pay', $invoice), ['amount' => 100])->assertRedirect();
        $csv = "transaction_id,date,description,amount\nDEP-1,".today()->toDateString().",Receipt,100.00\n";
        $this->actingAs($manager)->post(route('bank-reconciliation.import'), ['bank_account_id' => $bankAccount->id, 'file' => UploadedFile::fake()->createWithContent('bank.csv', $csv)])->assertRedirect();
        $line = BankStatementLine::sole();
        $clearing = JournalLine::whereHas('account', fn ($query) => $query->where('code', '1150'))->sole();
        $this->actingAs($manager)->post(route('bank-reconciliation.lines.match', $line), ['journal_line_id' => $clearing->id])->assertRedirect();
        $this->actingAs($manager)->post(route('bank-reconciliation.lines.settle', $line))->assertRedirect();
        $settlement = BankSettlement::sole();
        $this->assertDatabaseHas('journal_entries', ['id' => $settlement->journal_entry_id, 'event' => 'bank.settled']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $settlement->journal_entry_id, 'ledger_account_id' => $bankLedger->id, 'debit' => '100.00']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $settlement->journal_entry_id, 'ledger_account_id' => $clearing->ledger_account_id, 'credit' => '100.00']);
        $this->actingAs($manager)->post(route('bank-reconciliation.lines.settle', $line))->assertStatus(422);
        $this->actingAs($manager)->post(route('bank-reconciliation.lines.unmatch', $line))->assertStatus(422);
        $this->actingAs($manager)->post(route('accounting.journals.reverse', $settlement->journalEntry), ['posted_on' => today()->toDateString()])->assertStatus(422);
        $this->actingAs($manager)->post(route('bank-reconciliation.lines.reverse-settlement', $line), ['posted_on' => today()->toDateString()])->assertRedirect();
        $this->assertNotNull($settlement->fresh()->reversal_journal_entry_id);
        $this->actingAs($manager)->post(route('bank-reconciliation.lines.unmatch', $line))->assertRedirect();

        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Vendor']);
        $bill = VendorBill::create(['organization_id' => $organization->id, 'vendor_id' => $vendor->id, 'reference' => 'BIL-SETTLE', 'description' => 'Service', 'accounting_treatment' => 'operating_expense', 'bill_date' => today(), 'total' => 50]);
        $this->actingAs($manager)->post(route('vendor-bills.post', $bill))->assertRedirect();
        $this->actingAs($manager)->post(route('vendor-bills.pay', $bill), ['amount' => 50])->assertRedirect();
        $withdrawal = BankStatementLine::create(['organization_id' => $organization->id, 'bank_account_id' => $bankAccount->id, 'imported_by' => $manager->id, 'import_batch' => (string) Str::uuid(), 'external_id' => 'OUT-1', 'occurred_on' => today(), 'description' => 'Vendor payment', 'amount' => '-50.00']);
        $paymentClearing = JournalLine::whereHas('account', fn ($query) => $query->where('code', '1190'))->sole();
        $this->actingAs($manager)->post(route('bank-reconciliation.lines.match', $withdrawal), ['journal_line_id' => $paymentClearing->id])->assertRedirect();
        $this->actingAs($manager)->post(route('bank-reconciliation.lines.settle', $withdrawal))->assertRedirect();
        $withdrawalSettlement = BankSettlement::where('bank_statement_line_id', $withdrawal->id)->sole();
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $withdrawalSettlement->journal_entry_id, 'ledger_account_id' => $paymentClearing->ledger_account_id, 'debit' => '50.00']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $withdrawalSettlement->journal_entry_id, 'ledger_account_id' => $bankLedger->id, 'credit' => '50.00']);
    }

    public function test_closed_period_prevents_bank_settlement(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $bankLedger = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1010', 'name' => 'Bank', 'type' => 'asset']);
        $bank = BankAccount::create(['organization_id' => $organization->id, 'name' => 'Bank', 'currency' => 'AED', 'ledger_account_id' => $bankLedger->id]);
        $period = AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth()]);
        $invoice = Invoice::create(['organization_id' => $organization->id, 'reference' => 'INV-LOCK', 'accounting_treatment' => 'revenue', 'due_on' => today(), 'subtotal' => 10, 'total' => 10]);
        $this->actingAs($manager)->post(route('invoices.post', $invoice))->assertRedirect();
        $this->actingAs($manager)->post(route('invoices.pay', $invoice), ['amount' => 10])->assertRedirect();
        $line = BankStatementLine::create(['organization_id' => $organization->id, 'bank_account_id' => $bank->id, 'imported_by' => $manager->id, 'import_batch' => (string) Str::uuid(), 'external_id' => 'DEP', 'occurred_on' => today(), 'description' => 'Receipt', 'amount' => '10.00']);
        $clearing = JournalLine::whereHas('account', fn ($query) => $query->where('code', '1150'))->sole();
        $this->actingAs($manager)->post(route('bank-reconciliation.lines.match', $line), ['journal_line_id' => $clearing->id])->assertRedirect();
        $period->update(['status' => 'closed']);
        $this->actingAs($manager)->post(route('bank-reconciliation.lines.settle', $line))->assertStatus(422);
        $this->assertDatabaseCount('bank_settlements', 0);
    }
}
