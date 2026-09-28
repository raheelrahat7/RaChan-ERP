<?php

namespace Tests\Feature\Finance;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Accounting\Queries\VatReturnPreparation;
use App\Domain\Finance\Models\VendorCreditNote;
use App\Domain\Finance\Queries\OutstandingBalances;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorCreditNoteWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_credit_requires_owner_approval_reduces_payable_and_adjusts_vat(): void
    {
        $organization = Organization::factory()->create(['vat_enabled' => true, 'tax_registration_number' => '100123456789012']);
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $organization->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth()]);
        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Supplier']);
        $this->actingAs($manager)->post(route('vendor-bills.store'), ['vendor_id' => $vendor->id, 'description' => 'Parts', 'bill_date' => today()->toDateString(), 'total' => 1050, 'accounting_treatment' => 'operating_expense', 'vat_treatment' => 'standard', 'input_vat_recoverable' => true])->assertRedirect();
        $bill = VendorBill::sole();
        $this->post(route('vendor-bills.post', $bill))->assertRedirect();

        $this->post(route('vendor-credit-notes.store', $bill), ['amount' => 1051, 'posted_on' => today()->toDateString(), 'reason' => 'Return of parts'])->assertSessionHasErrors('amount');
        $this->post(route('vendor-credit-notes.store', $bill), ['amount' => 105, 'posted_on' => today()->toDateString(), 'reason' => 'Return of parts'])->assertRedirect();
        $credit = VendorCreditNote::sole();
        $this->post(route('vendor-credit-notes.approve', $credit))->assertSessionHasErrors('credit_note');
        $this->actingAs($owner)->post(route('vendor-credit-notes.approve', $credit))->assertRedirect();
        $credit->refresh();

        $this->assertSame('5.00', $credit->vat_amount);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $credit->journal_entry_id, 'ledger_account_id' => LedgerAccount::where('code', '2100')->sole()->id, 'debit' => 105]);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $credit->journal_entry_id, 'ledger_account_id' => LedgerAccount::where('code', '1250')->sole()->id, 'credit' => 5]);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $credit->journal_entry_id, 'ledger_account_id' => LedgerAccount::where('code', '5000')->sole()->id, 'credit' => 100]);
        $this->assertSame('945.00', app(OutstandingBalances::class)->for($organization, today()->toDateString())['total_payables']);
        $vat = app(VatReturnPreparation::class)->for($organization, today()->startOfMonth()->toDateString(), today()->toDateString());
        $this->assertSame('45.00', $vat['totals']['recoverable_input_vat']);

        $this->actingAs($owner)->post(route('vendor-credit-notes.reverse', $credit), ['posted_on' => today()->toDateString(), 'reason' => 'Supplier corrected the claim'])->assertRedirect();
        $this->assertSame('1050.00', app(OutstandingBalances::class)->for($organization, today()->toDateString())['total_payables']);
        $vat = app(VatReturnPreparation::class)->for($organization, today()->startOfMonth()->toDateString(), today()->toDateString());
        $this->assertSame('50.00', $vat['totals']['recoverable_input_vat']);
        $this->post(route('accounting.journals.reverse', $credit->journal_entry_id), ['posted_on' => today()->toDateString()])->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance.vendor_credit_note.approved', 'subject_id' => $credit->id]);
    }
}
