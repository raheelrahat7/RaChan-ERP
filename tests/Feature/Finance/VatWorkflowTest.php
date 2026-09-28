<?php

namespace Tests\Feature\Finance;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Accounting\Models\VatReturn;
use App\Domain\Accounting\Models\VatSettlement;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VatWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_vat_registered_organization_posts_output_and_recoverable_input_vat(): void
    {
        $organization = Organization::factory()->create(['vat_enabled' => true, 'tax_registration_number' => '100123456789012']);
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $organization->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth()]);

        $this->actingAs($manager)->post(route('invoices.store'), ['description' => 'Commercial rent', 'quantity' => 1, 'unit_price' => 1000, 'due_on' => today()->addWeek()->toDateString(), 'accounting_treatment' => 'revenue'])->assertSessionHasErrors('vat_treatment');
        $this->actingAs($manager)->post(route('invoices.store'), ['description' => 'Commercial rent', 'quantity' => 1, 'unit_price' => 1000, 'due_on' => today()->addWeek()->toDateString(), 'accounting_treatment' => 'revenue', 'vat_treatment' => 'standard'])->assertRedirect();
        $invoice = Invoice::sole();
        $this->assertSame('50.00', $invoice->vat_amount);
        $this->assertSame('1050.00', $invoice->total);
        $this->actingAs($manager)->post(route('invoices.post', $invoice))->assertRedirect();
        $invoiceJournal = JournalEntry::where('event', 'invoice.posted')->sole();
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $invoiceJournal->id, 'ledger_account_id' => LedgerAccount::where('code', '2200')->sole()->id, 'credit' => 50]);

        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'VAT Supplier']);
        $this->actingAs($manager)->post(route('vendor-bills.store'), ['vendor_id' => $vendor->id, 'description' => 'Repairs', 'bill_date' => today()->toDateString(), 'total' => 1050, 'accounting_treatment' => 'operating_expense', 'vat_treatment' => 'standard', 'input_vat_recoverable' => true])->assertRedirect();
        $bill = VendorBill::sole();
        $this->assertSame('50.00', $bill->vat_amount);
        $this->actingAs($manager)->post(route('vendor-bills.post', $bill))->assertRedirect();
        $billJournal = JournalEntry::where('event', 'vendor_bill.posted')->sole();
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $billJournal->id, 'ledger_account_id' => LedgerAccount::where('code', '1250')->sole()->id, 'debit' => 50]);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $billJournal->id, 'ledger_account_id' => LedgerAccount::where('code', '5000')->sole()->id, 'debit' => 1000]);
        $this->actingAs($manager)->get(route('accounting.vat-return', ['period' => today()->format('Y-m')]))->assertInertia(fn (Assert $page) => $page->component('finance/VatReturn')->where('frequency', 'quarterly')->where('report.totals.standard_sales_net', '1000.00')->where('report.totals.output_vat', '50.00')->where('report.totals.recoverable_input_vat', '50.00')->where('report.totals.net_vat', '0.00')->has('report.sales', 1)->has('report.purchases', 1));
        $csv = $this->actingAs($manager)->get(route('accounting.vat-return.export', ['period' => today()->format('Y-m')]))->assertOk()->streamedContent();
        $this->assertStringContainsString('VAT201 preparation', $csv);
        $this->assertStringContainsString($invoice->reference, $csv);
        $this->actingAs($manager)->post(route('accounting.vat-return.prepare'), ['period' => today()->format('Y-m')])->assertRedirect();
        $return = VatReturn::sole();
        $this->assertSame('50.00', $return->snapshot['totals']['output_vat']);
        $this->actingAs($manager)->post(route('accounting.vat-return.file', $return), ['filed_on' => today()->toDateString(), 'fta_reference' => 'FTA-201-1'])->assertForbidden();
        $this->actingAs($owner)->post(route('accounting.vat-return.file', $return), ['filed_on' => today()->toDateString(), 'fta_reference' => 'FTA-201-1'])->assertRedirect();
        $journalCount = JournalEntry::count();
        $adjustment = ['discovered_on' => today()->toDateString(), 'output_vat_delta' => '10001.00', 'input_vat_delta' => '0.00', 'correction_method' => 'current_return', 'reason' => 'Late taxable supply identified.'];
        $this->actingAs($manager)->post(route('accounting.vat-return.adjustments.store', $return), $adjustment)->assertStatus(422);
        $this->actingAs($manager)->post(route('accounting.vat-return.adjustments.store', $return), [...$adjustment, 'correction_method' => 'voluntary_disclosure'])->assertRedirect();
        $this->assertDatabaseHas('vat_return_adjustments', ['vat_return_id' => $return->id, 'correction_method' => 'voluntary_disclosure']);
        $this->assertSame($journalCount, JournalEntry::count());

        $bankLedger = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1010', 'name' => 'Operating Bank', 'type' => 'asset', 'is_active' => true]);
        $bank = BankAccount::create(['organization_id' => $organization->id, 'name' => 'Operating Bank', 'currency' => 'AED', 'ledger_account_id' => $bankLedger->id]);
        $this->actingAs($manager)->post(route('accounting.vat-return.settle', $return), ['bank_account_id' => $bank->id, 'posted_on' => today()->toDateString()])->assertRedirect();
        $settlement = VatSettlement::sole();
        $this->assertSame('payment', $settlement->type);
        $this->assertSame('10001.00', $settlement->net_vat);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $settlement->journal_entry_id, 'ledger_account_id' => LedgerAccount::where('code', '2200')->sole()->id, 'debit' => 10051]);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $settlement->journal_entry_id, 'ledger_account_id' => LedgerAccount::where('code', '1250')->sole()->id, 'credit' => 50]);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $settlement->journal_entry_id, 'ledger_account_id' => $bankLedger->id, 'credit' => 10001]);
        $this->actingAs($manager)->post(route('accounting.journals.reverse', $settlement->journal_entry_id), ['posted_on' => today()->toDateString()])->assertStatus(422);
        $this->actingAs($manager)->post(route('accounting.vat-return.adjustments.store', $return), [...$adjustment, 'correction_method' => 'voluntary_disclosure'])->assertStatus(422);
        $this->actingAs($manager)->post(route('accounting.vat-return.settlement.reverse', $settlement), ['posted_on' => today()->toDateString()])->assertRedirect();
        $this->assertNotNull($settlement->fresh()->reversal_journal_entry_id);
        $this->actingAs($manager)->post(route('accounting.vat-return.settle', $return), ['bank_account_id' => $bank->id, 'posted_on' => today()->toDateString()])->assertRedirect();
        $this->assertCount(2, VatSettlement::all());
    }

    public function test_refundable_vat_clears_to_receivable_and_records_the_later_bank_receipt(): void
    {
        $organization = Organization::factory()->create(['vat_enabled' => true, 'tax_registration_number' => '100123456789012']);
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth()]);
        LedgerAccount::create(['organization_id' => $organization->id, 'code' => '2200', 'name' => 'Output VAT Payable', 'type' => 'liability', 'is_active' => true]);
        LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1250', 'name' => 'Recoverable Input VAT', 'type' => 'asset', 'is_active' => true]);
        $bankLedger = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1010', 'name' => 'Operating Bank', 'type' => 'asset', 'is_active' => true]);
        $bank = BankAccount::create(['organization_id' => $organization->id, 'name' => 'Operating Bank', 'currency' => 'AED', 'ledger_account_id' => $bankLedger->id]);
        $return = VatReturn::create([
            'organization_id' => $organization->id, 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth(),
            'status' => 'filed', 'snapshot' => ['totals' => ['output_vat' => '10.00', 'recoverable_input_vat' => '50.00']],
            'prepared_by' => $manager->id, 'filed_by' => $manager->id, 'filed_on' => today(), 'fta_reference' => 'FTA-R-1',
        ]);

        $this->actingAs($manager)->post(route('accounting.vat-return.settle', $return), ['bank_account_id' => $bank->id, 'posted_on' => today()->toDateString()])->assertRedirect();
        $settlement = VatSettlement::sole();
        $this->assertSame('refund_receivable', $settlement->type);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $settlement->journal_entry_id, 'ledger_account_id' => LedgerAccount::where('code', '1260')->sole()->id, 'debit' => 40]);
        $this->actingAs($manager)->post(route('accounting.vat-return.refund.receive', $settlement), ['posted_on' => today()->toDateString()])->assertRedirect();
        $settlement->refresh();
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $settlement->receipt_journal_entry_id, 'ledger_account_id' => $bankLedger->id, 'debit' => 40]);
        $this->actingAs($manager)->post(route('accounting.vat-return.settlement.reverse', $settlement), ['posted_on' => today()->toDateString()])->assertStatus(422);
        $this->actingAs($manager)->post(route('accounting.vat-return.refund.reverse', $settlement), ['posted_on' => today()->toDateString()])->assertRedirect();
        $this->actingAs($manager)->post(route('accounting.vat-return.settlement.reverse', $settlement), ['posted_on' => today()->toDateString()])->assertRedirect();
    }
}
