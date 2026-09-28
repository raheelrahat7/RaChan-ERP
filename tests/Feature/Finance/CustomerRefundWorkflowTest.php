<?php

namespace Tests\Feature\Finance;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Finance\Models\CustomerCreditNote;
use App\Domain\Finance\Models\CustomerRefund;
use App\Domain\Finance\Services\InvoiceBalance;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerRefundWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_cash_refund_requires_owner_approval_and_posts_auditable_journal(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $organization->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth()]);
        $this->actingAs($manager)->post(route('invoices.store'), ['description' => 'Service', 'quantity' => 1, 'unit_price' => 100, 'due_on' => today()->toDateString(), 'accounting_treatment' => 'revenue'])->assertRedirect();
        $invoice = Invoice::sole();
        $this->post(route('invoices.post', $invoice))->assertRedirect();
        $this->post(route('invoices.pay', $invoice), ['amount' => 80])->assertRedirect();
        $invoice->refresh();
        $this->post(route('credit-notes.store', $invoice), ['amount' => 100, 'reason' => 'Service cancelled'])->assertRedirect()->assertSessionHasNoErrors();
        $note = CustomerCreditNote::query()->sole();
        $this->post(route('credit-notes.post', $note), ['posted_on' => today()->toDateString()])->assertRedirect();

        $this->post(route('customer-refunds.store', $invoice), ['credit_note_id' => $note->id, 'amount' => 100, 'posted_on' => today()->toDateString(), 'reason' => 'Return customer funds'])->assertSessionHasErrors('amount');
        $this->post(route('customer-refunds.store', $invoice), ['credit_note_id' => $note->id, 'amount' => 80, 'posted_on' => today()->toDateString(), 'reason' => 'Return customer funds'])->assertRedirect();
        $refund = CustomerRefund::sole();
        $this->post(route('customer-refunds.approve', $refund))->assertSessionHasErrors('refund');
        $this->actingAs($owner)->post(route('customer-refunds.approve', $refund))->assertRedirect();
        $refund->refresh();

        $this->assertSame('posted', $refund->status);
        $this->assertDatabaseHas('journal_entries', ['id' => $refund->journal_entry_id, 'event' => 'customer.refund_approved', 'debit_total' => 80, 'credit_total' => 80]);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $refund->journal_entry_id, 'ledger_account_id' => LedgerAccount::where('code', '1100')->sole()->id, 'debit' => 80]);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $refund->journal_entry_id, 'ledger_account_id' => LedgerAccount::where('code', '1190')->sole()->id, 'credit' => 80]);
        $this->assertSame(0, app(InvoiceBalance::class)->refundableCashCents($invoice));
        $this->post(route('accounting.journals.reverse', $refund->journal_entry_id), ['posted_on' => today()->toDateString()])->assertStatus(422);
        $this->travelTo(today()->addDay());
        $this->actingAs($owner)->post(route('customer-refunds.reverse', $refund), ['posted_on' => today()->toDateString(), 'reason' => 'Duplicate payout'])->assertRedirect();
        $this->assertSame(0, app(InvoiceBalance::class)->refundableCashCents($invoice, today()->subDay()->toDateString()));
        $this->assertSame(8000, app(InvoiceBalance::class)->refundableCashCents($invoice));
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance.customer_refund.approved', 'subject_id' => $refund->id]);
    }

    public function test_security_deposit_offsets_do_not_count_as_refundable_cash(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $this->actingAs($manager)->post(route('invoices.store'), ['description' => 'Rent', 'quantity' => 1, 'unit_price' => 100, 'due_on' => today()->toDateString(), 'accounting_treatment' => 'revenue'])->assertRedirect();
        $invoice = Invoice::sole();
        $this->post(route('invoices.post', $invoice))->assertRedirect();
        $invoice->update(['status' => 'paid']);
        Payment::create(['organization_id' => $organization->id, 'invoice_id' => $invoice->id, 'reference' => 'OFFSET', 'amount' => 105, 'currency' => 'AED', 'received_on' => today(), 'method' => 'security_deposit_offset']);

        $this->assertSame(0, app(InvoiceBalance::class)->directCashReceivedCents($invoice));
        $this->assertSame(0, app(InvoiceBalance::class)->refundableCashCents($invoice));
        $this->post(route('credit-notes.store', $invoice), ['amount' => 1, 'reason' => 'Offset only'])->assertSessionHasErrors('amount');
    }
}
