<?php

namespace Tests\Feature\Finance;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Finance\Models\VendorCashRefund;
use App\Domain\Finance\Models\VendorCreditNote;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\JournalEntry;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VendorCashRefundTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $manager;

    private VendorBill $bill;

    private VendorCreditNote $credit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->manager = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->organization->users()->attach($this->owner, ['role' => OrganizationRole::Owner->value]);
        $this->organization->users()->attach($this->manager, ['role' => OrganizationRole::Manager->value]);
        AccountingPeriod::create(['organization_id' => $this->organization->id, 'name' => 'Current', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth()]);
        $vendor = MaintenanceVendor::create(['organization_id' => $this->organization->id, 'name' => 'Supplier']);
        $this->actingAs($this->manager)->post(route('vendor-bills.store'), ['vendor_id' => $vendor->id, 'description' => 'Parts', 'bill_date' => today()->format('Y-m-d'), 'total' => 100, 'accounting_treatment' => 'operating_expense'])->assertRedirect();
        $this->bill = VendorBill::sole();
        $this->post(route('vendor-bills.post', $this->bill))->assertRedirect();
        $this->post(route('vendor-bills.pay', $this->bill), ['amount' => 100])->assertRedirect();
        $this->post(route('vendor-credit-notes.store', $this->bill), ['amount' => 20, 'posted_on' => today()->format('Y-m-d'), 'reason' => 'Returned parts'])->assertRedirect();
        $this->credit = VendorCreditNote::sole();
        $this->actingAs($this->owner)->post(route('vendor-credit-notes.approve', $this->credit))->assertRedirect();
    }

    /** @return array<string,mixed> */
    private function input(string $amount = '20'): array
    {
        return ['credit_note_id' => $this->credit->id, 'amount' => $amount, 'posted_on' => today()->format('Y-m-d'), 'reason' => 'Supplier bank transfer returned overpayment', 'operation_key' => (string) Str::uuid()];
    }

    public function test_owner_approval_posts_cash_in_and_blocks_source_credit_and_generic_journal_reversal(): void
    {
        $input = $this->input();
        $this->actingAs($this->manager)->post(route('vendor-refunds.store', $this->bill), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('vendor-refunds.store', $this->bill), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('vendor_cash_refunds', 1);
        $refund = VendorCashRefund::sole();
        $this->post(route('vendor-refunds.approve', $refund))->assertForbidden();
        $this->actingAs($this->owner)->post(route('vendor-refunds.approve', $refund))->assertRedirect()->assertSessionHasNoErrors();
        $refund->refresh();
        $this->post(route('vendor-refunds.approve', $refund))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $refund->journal_entry_id, 'ledger_account_id' => LedgerAccount::where('code', '1190')->sole()->id, 'debit' => '20.00']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $refund->journal_entry_id, 'ledger_account_id' => LedgerAccount::where('code', '2100')->sole()->id, 'credit' => '20.00']);
        $this->post(route('accounting.journals.reverse', $refund->journal_entry_id), ['posted_on' => today()->format('Y-m-d')])->assertStatus(422);
        $this->post(route('vendor-credit-notes.reverse', $this->credit), ['posted_on' => today()->format('Y-m-d'), 'reason' => 'Cannot reverse source'])->assertSessionHasErrors('credit_note');
        $this->actingAs($this->manager)->post(route('vendor-refunds.store', $this->bill), $this->input('0.01'))->assertSessionHasErrors('amount');
        $this->actingAs($this->owner)->post(route('vendor-refunds.reverse', $refund), ['posted_on' => today()->format('Y-m-d'), 'reason' => 'Receipt reversed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('reversed', $refund->fresh()->status);
        $this->post(route('vendor-credit-notes.reverse', $this->credit), ['posted_on' => today()->format('Y-m-d'), 'reason' => 'Return cancelled'])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_capacity_and_maker_checker_and_closed_period_rechecks_prevent_invalid_cash_receipts(): void
    {
        $this->actingAs($this->manager)->post(route('vendor-refunds.store', $this->bill), $this->input('20.01'))->assertSessionHasErrors('amount');
        $this->actingAs($this->owner)->post(route('vendor-refunds.store', $this->bill), $this->input('1'))->assertRedirect()->assertSessionHasNoErrors();
        $own = VendorCashRefund::sole();
        $this->post(route('vendor-refunds.approve', $own))->assertSessionHasErrors('refund');
        $this->actingAs($this->manager)->post(route('vendor-refunds.store', $this->bill), $this->input())->assertRedirect()->assertSessionHasNoErrors();
        $refund = VendorCashRefund::latest('id')->firstOrFail();
        AccountingPeriod::where('organization_id', $this->organization->id)->update(['status' => 'closed']);
        $this->actingAs($this->owner)->post(route('vendor-refunds.approve', $refund))->assertStatus(422);
        $this->assertSame('submitted', $refund->fresh()->status);
        $this->assertNull($refund->fresh()->journal_entry_id);
    }

    public function test_bank_matching_and_active_receipts_protect_the_source_cash_capacity(): void
    {
        $this->actingAs($this->manager)->post(route('vendor-refunds.store', $this->bill), $this->input('10'))->assertSessionHasNoErrors();
        $refund = VendorCashRefund::sole();
        $this->actingAs($this->owner)->post(route('vendor-refunds.approve', $refund))->assertSessionHasNoErrors();
        $refund->refresh();
        $payment = JournalEntry::where('organization_id', $this->organization->id)->where('event', 'vendor_bill.paid')->sole();
        $this->post(route('accounting.journals.reverse', $payment), ['posted_on' => today()->format('Y-m-d')])->assertStatus(422);
        $bank = BankAccount::create(['organization_id' => $this->organization->id, 'name' => 'Refund bank', 'currency' => 'AED']);
        $line = JournalLine::where('journal_entry_id', $refund->journal_entry_id)->where('debit', '10.00')->sole();
        $match = BankStatementLine::create(['organization_id' => $this->organization->id, 'bank_account_id' => $bank->id, 'imported_by' => $this->manager->id, 'import_batch' => (string) Str::uuid(), 'external_id' => 'REFUND-1', 'occurred_on' => today(), 'description' => 'Supplier return', 'amount' => '10.00', 'matched_journal_line_id' => $line->id, 'matched_by' => $this->manager->id, 'matched_at' => now()]);
        $this->post(route('vendor-refunds.reverse', $refund), ['posted_on' => today()->format('Y-m-d'), 'reason' => 'Receipt correction'])->assertSessionHasErrors('refund');
        $this->assertSame('posted', $refund->fresh()->status);
        $match->update(['matched_journal_line_id' => null, 'matched_by' => null, 'matched_at' => null]);
        $this->post(route('vendor-refunds.reverse', $refund), ['posted_on' => today()->format('Y-m-d'), 'reason' => 'Receipt correction'])->assertSessionHasNoErrors();
        $this->assertSame('reversed', $refund->fresh()->status);
    }
}
