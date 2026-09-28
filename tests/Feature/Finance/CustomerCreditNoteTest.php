<?php

namespace Tests\Feature\Finance;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Accounting\Queries\VatReturnPreparation;
use App\Domain\Finance\Models\CustomerCreditNote;
use App\Domain\Finance\Queries\OutstandingBalances;
use App\Domain\Finance\Services\InvoiceBalance;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Leasing\Models\LeaseDepositDeduction;
use App\Domain\Leasing\Models\LeaseDepositSettlement;
use App\Domain\Leasing\Models\LeaseSecurityDeposit;
use App\Domain\Leasing\Models\LeaseServiceCharge;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Lease;
use App\Models\Organization;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerCreditNoteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $manager;

    private Invoice $invoice;

    private AccountingPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 1)->startOfDay());
        $this->org = Organization::factory()->create(['vat_enabled' => true]);
        $this->manager = User::factory()->create(['current_organization_id' => $this->org->id]);
        $this->org->users()->attach($this->manager, ['role' => OrganizationRole::Manager->value]);
        $this->period = AccountingPeriod::create(['organization_id' => $this->org->id, 'name' => 'September', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
        $this->actingAs($this->manager)->post(route('invoices.store'), ['description' => 'Service charge', 'quantity' => 1, 'unit_price' => '100.00', 'due_on' => '2026-09-05', 'accounting_treatment' => 'revenue', 'vat_treatment' => 'standard'])->assertRedirect();
        $this->invoice = Invoice::sole();
        $this->post(route('invoices.post', $this->invoice))->assertRedirect();
        $this->invoice->refresh();
        $this->travelTo(now()->setDate(2026, 9, 27));
    }

    public function test_credit_posting_reverses_original_accounts_and_reversal_restores_dated_balances(): void
    {
        $note = $this->draft('42.00');
        $this->assertSame(10500, app(InvoiceBalance::class)->outstandingCents($this->invoice));
        $this->post(route('credit-notes.post', $note), ['posted_on' => '2026-09-10'])->assertRedirect();
        $note->refresh();
        $this->assertSame('2.00', $note->vat_amount);
        foreach (['4000' => [40, 0], '2200' => [2, 0], '1100' => [0, 42]] as $code => [$debit, $credit]) {
            $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $note->journal_entry_id, 'ledger_account_id' => LedgerAccount::where('code', $code)->sole()->id, 'debit' => $debit, 'credit' => $credit]);
        }
        $this->assertSame('partial', $this->invoice->fresh()->status);
        $this->assertBalance('2026-09-09', '105.00');
        $this->assertBalance('2026-09-10', '63.00');
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('metrics.outstandingAed', 63));
        $csv = $this->get(route('accounting.outstanding-balances.export', ['as_of' => '2026-09-10']))->assertOk()->streamedContent();
        $this->assertStringContainsString('105.00,0.00,42.00,63.00', $csv);
        $this->post(route('credit-notes.post', $note), ['posted_on' => '2026-09-10'])->assertSessionHasErrors('credit_note');
        $this->post(route('accounting.journals.reverse', $note->journal_entry_id), ['posted_on' => '2026-09-11'])->assertStatus(422);
        $original = JournalEntry::where('event', 'invoice.posted')->sole();
        $this->post(route('accounting.journals.reverse', $original), ['posted_on' => '2026-09-11'])->assertStatus(422);
        $this->post(route('credit-notes.reverse', $note), ['posted_on' => '2026-09-20', 'reason' => 'Incorrect adjustment'])->assertRedirect();
        $this->assertSame('posted', $this->invoice->fresh()->status);
        $this->assertBalance('2026-09-19', '63.00');
        $this->assertBalance('2026-09-20', '105.00');
        $this->post(route('credit-notes.reverse', $note), ['posted_on' => '2026-09-21', 'reason' => 'Repeat'])->assertSessionHasErrors('credit_note');
        $this->assertDatabaseHas('journal_entries', ['id' => $note->fresh()->reversal_journal_entry_id, 'reversal_of_id' => $note->journal_entry_id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance.credit_note.reversed', 'subject_id' => $note->id]);
    }

    public function test_payments_recheck_credit_adjusted_balance_and_fully_credited_invoices_reopen(): void
    {
        $note = $this->draft('105.00');
        $this->post(route('credit-notes.post', $note), ['posted_on' => '2026-09-10'])->assertRedirect();
        $this->assertSame('paid', $this->invoice->fresh()->status);
        $this->post(route('invoices.pay', $this->invoice), ['amount' => 1])->assertStatus(422);
        $this->assertBalance('2026-09-10', '0.00');
        $this->post(route('credit-notes.reverse', $note), ['posted_on' => '2026-09-20', 'reason' => 'Reopen'])->assertRedirect();
        $second = $this->draft('42.00');
        $this->post(route('credit-notes.post', $second), ['posted_on' => '2026-09-21'])->assertRedirect();
        $this->post(route('invoices.pay', $this->invoice), ['amount' => 64])->assertSessionHasErrors('amount');
        $this->post(route('invoices.pay', $this->invoice), ['amount' => 63])->assertRedirect();
        $this->assertSame('paid', $this->invoice->fresh()->status);
        $this->post(route('credit-notes.reverse', $second), ['posted_on' => '2026-09-27', 'reason' => 'Correction'])->assertRedirect();
        $this->assertSame('partial', $this->invoice->fresh()->status);
        $this->assertSame(4200, app(InvoiceBalance::class)->outstandingCents($this->invoice));
    }

    public function test_draft_balance_is_rechecked_after_payment_and_other_credits(): void
    {
        $first = $this->draft('80.00');
        $second = $this->draft('80.00');
        $this->post(route('invoices.pay', $this->invoice), ['amount' => 30])->assertRedirect();
        $this->post(route('credit-notes.post', $first), ['posted_on' => '2026-09-27'])->assertRedirect();
        $this->post(route('credit-notes.post', $second), ['posted_on' => '2026-09-27'])->assertSessionHasErrors('amount');
        $small = $this->draft('25.00');
        $this->post(route('credit-notes.post', $small), ['posted_on' => '2026-09-27'])->assertRedirect();
        $this->assertSame(0, app(InvoiceBalance::class)->outstandingCents($this->invoice));
        $this->assertSame(3000, app(InvoiceBalance::class)->refundableCashCents($this->invoice));
        $this->assertDatabaseCount('customer_credit_notes', 3);
        $this->assertSame(2, JournalEntry::where('event', 'credit_note.posted')->count());
    }

    public function test_closed_missing_period_and_inactive_accounts_leave_no_partial_postings(): void
    {
        $note = $this->draft('10.00');
        $this->period->update(['status' => 'closed']);
        $this->post(route('credit-notes.post', $note), ['posted_on' => '2026-09-10'])->assertSessionHasErrors('credit_note');
        $this->period->update(['status' => 'open', 'ends_on' => '2026-09-09']);
        $this->post(route('credit-notes.post', $note), ['posted_on' => '2026-09-10'])->assertSessionHasErrors('credit_note');
        $this->period->update(['ends_on' => '2026-09-30']);
        LedgerAccount::where('code', '4000')->update(['is_active' => false]);
        $this->post(route('credit-notes.post', $note), ['posted_on' => '2026-09-10'])->assertSessionHasErrors('credit_note');
        $this->assertSame('draft', $note->fresh()->status);
        $this->assertSame(0, JournalEntry::where('event', 'credit_note.posted')->count());
        LedgerAccount::where('code', '4000')->update(['is_active' => true]);
        $this->post(route('credit-notes.post', $note), ['posted_on' => '2026-09-10'])->assertRedirect();
        $this->period->update(['status' => 'closed']);
        $this->post(route('credit-notes.reverse', $note), ['posted_on' => '2026-09-20', 'reason' => 'Correction'])->assertSessionHasErrors('credit_note');
        $this->assertSame('posted', $note->fresh()->status);
        $this->assertSame(9500, app(InvoiceBalance::class)->outstandingCents($this->invoice));
    }

    public function test_tenant_and_role_boundaries_apply_to_every_mutation(): void
    {
        $note = $this->draft('10.00');
        $member = User::factory()->create(['current_organization_id' => $this->org->id]);
        $this->org->users()->attach($member, ['role' => OrganizationRole::Member->value]);
        $this->actingAs($member)->post(route('credit-notes.store', $this->invoice), ['amount' => 1, 'reason' => 'Test'])->assertForbidden();
        $this->post(route('credit-notes.post', $note), ['posted_on' => '2026-09-10'])->assertForbidden();
        $this->post(route('credit-notes.reverse', $note), ['posted_on' => '2026-09-10', 'reason' => 'Test'])->assertForbidden();
        $other = Organization::factory()->create();
        $stranger = User::factory()->create(['current_organization_id' => $other->id]);
        $other->users()->attach($stranger, ['role' => OrganizationRole::Owner->value]);
        $this->actingAs($stranger)->post(route('credit-notes.store', $this->invoice), ['amount' => 1, 'reason' => 'Test'])->assertNotFound();
        $this->post(route('credit-notes.post', $note), ['posted_on' => '2026-09-10'])->assertNotFound();
        $this->post(route('credit-notes.reverse', $note), ['posted_on' => '2026-09-10', 'reason' => 'Test'])->assertNotFound();
        $this->get(route('invoices.index'))->assertInertia(fn (Assert $page) => $page->has('invoices', 0));
    }

    public function test_ineligible_invoices_and_invalid_amounts_are_rejected(): void
    {
        foreach ([0, -1, '1.001', 106] as $amount) {
            $this->post(route('credit-notes.store', $this->invoice), ['amount' => $amount, 'reason' => 'Test'])->assertSessionHasErrors('amount');
        }
        $this->post(route('credit-notes.store', $this->invoice), ['amount' => 1, 'reason' => ' '])->assertSessionHasErrors('reason');
        foreach ([['status' => 'draft'], ['accounting_treatment' => 'refundable_deposit'], ['currency' => 'USD']] as $attributes) {
            $this->invoice->update(['status' => 'posted', 'currency' => 'AED', 'accounting_treatment' => 'revenue', ...$attributes]);
            $this->post(route('credit-notes.store', $this->invoice), ['amount' => 1, 'reason' => 'Test'])->assertSessionHasErrors('invoice');
        }
        $this->invoice->update(['currency' => 'AED']);
        JournalEntry::where('event', 'invoice.posted')->sole()->lines()->delete();
        $this->post(route('credit-notes.store', $this->invoice), ['amount' => 1, 'reason' => 'Test'])->assertSessionHasErrors('invoice');
        $this->assertDatabaseCount('customer_credit_notes', 0);
    }

    public function test_reversed_source_invoice_cannot_receive_a_credit(): void
    {
        $note = $this->draft('10.00');
        $entry = JournalEntry::where('event', 'invoice.posted')->sole();
        $this->post(route('accounting.journals.reverse', $entry), ['posted_on' => '2026-09-10'])->assertRedirect();
        $this->post(route('credit-notes.post', $note), ['posted_on' => '2026-09-11'])->assertSessionHasErrors('invoice');
    }

    public function test_vat_preparation_and_historical_reports_include_dated_credits_and_reversals(): void
    {
        $note = $this->draft('42.00');
        $this->post(route('credit-notes.post', $note), ['posted_on' => '2026-09-10'])->assertRedirect();
        $report = app(VatReturnPreparation::class)->for($this->org, '2026-09-01', '2026-09-15');
        $this->assertSame('3.00', $report['totals']['output_vat']);
        $this->assertSame('60.00', $report['totals']['standard_sales_net']);
        $this->post(route('credit-notes.reverse', $note), ['posted_on' => '2026-09-20', 'reason' => 'Correction'])->assertRedirect();
        $report = app(VatReturnPreparation::class)->for($this->org, '2026-09-16', '2026-09-27');
        $this->assertSame('2.00', $report['totals']['output_vat']);
        $this->assertSame('40.00', $report['totals']['standard_sales_net']);
        $this->assertSame('3.00', app(VatReturnPreparation::class)->for($this->org, '2026-09-01', '2026-09-15')['totals']['output_vat']);
        $this->get(route('invoices.index'))->assertInertia(fn (Assert $page) => $page->where('invoices.0.credited_amount', '0.00')->where('invoices.0.credit_notes.0.status', 'reversed'));
    }

    public function test_small_credits_preserve_vat_rounding_across_multiple_postings(): void
    {
        foreach (['0.10', '0.10', '104.80'] as $amount) {
            $note = $this->draft($amount);
            $this->post(route('credit-notes.post', $note), ['posted_on' => '2026-09-10'])->assertRedirect();
        }
        $this->assertSame(500, (int) round((float) CustomerCreditNote::sum('vat_amount') * 100));
        $this->assertSame(0, app(InvoiceBalance::class)->outstandingCents($this->invoice));
        $this->assertSame('0.00', app(VatReturnPreparation::class)->for($this->org, '2026-09-01', '2026-09-27')['totals']['output_vat']);
    }

    public function test_dates_cannot_precede_source_or_latest_credit_activity_or_be_in_future(): void
    {
        $note = $this->draft('10.00');
        foreach (['2026-08-31', '2026-09-28'] as $date) {
            $this->post(route('credit-notes.post', $note), ['posted_on' => $date])->assertSessionHasErrors('posted_on');
        }
        $this->post(route('credit-notes.post', $note), ['posted_on' => '2026-09-15'])->assertRedirect();
        $second = $this->draft('10.00');
        $this->post(route('credit-notes.post', $second), ['posted_on' => '2026-09-14'])->assertSessionHasErrors('posted_on');
        $this->post(route('credit-notes.reverse', $note), ['posted_on' => '2026-09-14', 'reason' => 'Test'])->assertSessionHasErrors('posted_on');
    }

    public function test_non_vat_credit_uses_original_revenue_account_after_settings_change(): void
    {
        $this->org->update(['vat_enabled' => false]);
        $this->actingAs($this->manager->fresh());
        $this->post(route('invoices.store'), ['description' => 'Non VAT service', 'quantity' => 1, 'unit_price' => 50, 'due_on' => '2026-09-30', 'accounting_treatment' => 'revenue'])->assertRedirect()->assertSessionHasNoErrors();
        $invoice = Invoice::latest('id')->firstOrFail();
        $this->post(route('invoices.post', $invoice))->assertRedirect();
        $this->org->update(['vat_enabled' => true]);
        $this->actingAs($this->manager->fresh());
        $this->post(route('credit-notes.store', $invoice), ['amount' => 50, 'reason' => 'Cancel service'])->assertRedirect();
        $note = CustomerCreditNote::sole();
        $this->post(route('credit-notes.post', $note), ['posted_on' => '2026-09-27'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('0.00', $note->fresh()->vat_amount);
        $this->assertSame(2, JournalEntry::findOrFail($note->fresh()->journal_entry_id)->lines()->count());
        $this->assertSame(0, app(InvoiceBalance::class)->outstandingCents($invoice));
    }

    public function test_owner_statements_and_rent_offsets_use_credit_adjusted_amounts(): void
    {
        $property = Property::create(['organization_id' => $this->org->id, 'name' => 'Tower', 'type' => 'residential']);
        $owner = Owner::create(['organization_id' => $this->org->id, 'name' => 'Half owner']);
        $property->owners()->attach($owner, ['ownership_share' => 50]);
        $unit = Unit::create(['organization_id' => $this->org->id, 'property_id' => $property->id, 'number' => '1', 'type' => 'apartment']);
        $lease = Lease::create(['organization_id' => $this->org->id, 'unit_id' => $unit->id, 'reference' => 'LSE-CREDIT', 'status' => 'active', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        LeaseServiceCharge::create(['organization_id' => $this->org->id, 'lease_id' => $lease->id, 'invoice_id' => $this->invoice->id, 'category' => 'service_charge', 'period_starts_on' => '2026-09-01', 'period_ends_on' => '2026-09-30', 'net_amount' => 100, 'due_on' => '2026-09-05', 'created_by' => $this->manager->id]);
        $depositInvoice = Invoice::create(['organization_id' => $this->org->id, 'reference' => 'INV-DEPOSIT', 'accounting_treatment' => 'refundable_deposit', 'total' => 100, 'subtotal' => 100]);
        $deposit = LeaseSecurityDeposit::create(['organization_id' => $this->org->id, 'lease_id' => $lease->id, 'invoice_id' => $depositInvoice->id, 'required_amount' => 100, 'due_on' => today(), 'created_by' => $this->manager->id]);
        $settlement = LeaseDepositSettlement::create(['organization_id' => $this->org->id, 'lease_security_deposit_id' => $deposit->id, 'status' => 'approved', 'created_by' => $this->manager->id]);
        $deduction = LeaseDepositDeduction::create(['organization_id' => $this->org->id, 'lease_deposit_settlement_id' => $settlement->id, 'category' => 'rent_arrears', 'description' => 'Rent offset', 'amount' => 64, 'invoice_id' => $this->invoice->id, 'created_by' => $this->manager->id]);
        $note = $this->draft('42.00');
        $this->post(route('credit-notes.post', $note), ['posted_on' => '2026-09-10'])->assertRedirect();
        $query = ['owner_id' => $owner->id, 'from' => '2026-09-01', 'to' => '2026-09-19'];
        $this->get(route('reports.owner-statements.index', $query))->assertInertia(fn (Assert $page) => $page->where('statement.totals.income', '30.00'));
        $this->post(route('lease-compliance.deposit-deductions.post-rent-offset', $deduction), ['posted_on' => '2026-09-20'])->assertStatus(422);
        $deduction->update(['amount' => 63]);
        $this->post(route('lease-compliance.deposit-deductions.post-rent-offset', $deduction), ['posted_on' => '2026-09-20'])->assertRedirect();
        $this->assertSame('paid', $this->invoice->fresh()->status);
        $this->post(route('lease-compliance.deposit-deductions.reverse-rent-offset', $deduction), ['posted_on' => '2026-09-21'])->assertRedirect();
        $this->assertSame('partial', $this->invoice->fresh()->status);
        $this->assertSame(6300, app(InvoiceBalance::class)->outstandingCents($this->invoice));
        $this->post(route('credit-notes.reverse', $note), ['posted_on' => '2026-09-22', 'reason' => 'Correction'])->assertRedirect();
        $this->get(route('reports.owner-statements.index', [...$query, 'to' => '2026-09-27']))->assertInertia(fn (Assert $page) => $page->where('statement.totals.income', '50.00'));
        $this->get(route('reports.owner-statements.index', $query))->assertInertia(fn (Assert $page) => $page->where('statement.totals.income', '30.00'));
    }

    private function draft(string $amount): CustomerCreditNote
    {
        $this->post(route('credit-notes.store', $this->invoice), ['amount' => $amount, 'reason' => 'Agreed price adjustment'])->assertRedirect()->assertSessionHasNoErrors();

        return CustomerCreditNote::latest('id')->firstOrFail();
    }

    private function assertBalance(string $asOf, string $amount): void
    {
        $this->assertSame($amount, app(OutstandingBalances::class)->for($this->org, $asOf)['total_receivables']);
    }
}
