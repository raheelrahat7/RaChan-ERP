<?php

namespace Tests\Feature\Finance;

use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_can_post_and_settle_an_aed_invoice_with_partial_payments(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);

        $this->actingAs($manager)->post(route('invoices.store'), ['description' => 'Lease deposit', 'quantity' => 1, 'unit_price' => 1000, 'due_on' => now()->addWeek()->toDateString(), 'accounting_treatment' => 'refundable_deposit'])->assertRedirect();
        $invoice = Invoice::sole();
        $this->actingAs($manager)->post(route('invoices.post', $invoice))->assertRedirect();
        $this->actingAs($manager)->post(route('invoices.pay', $invoice), ['amount' => 400])->assertRedirect();
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'partial', 'currency' => 'AED']);
        $this->actingAs($manager)->post(route('invoices.pay', $invoice), ['amount' => 600])->assertRedirect();
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'paid']);
        $this->assertDatabaseCount('journal_entries', 3);
        $this->assertDatabaseCount('journal_lines', 6);
        $invoiceJournal = JournalEntry::where('event', 'invoice.posted')->sole();
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $invoiceJournal->id, 'ledger_account_id' => LedgerAccount::where('code', '2300')->sole()->id, 'credit' => 1000]);
    }

    public function test_historical_draft_needs_treatment_and_historical_posted_payment_stays_unallocated(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $draft = Invoice::create(['organization_id' => $organization->id, 'reference' => 'INV-OLD-DRAFT', 'due_on' => today(), 'subtotal' => 100, 'total' => 100]);

        $this->actingAs($manager)->post(route('invoices.post', $draft))->assertStatus(422);
        $this->actingAs($manager)->put(route('invoices.treatment.update', $draft), ['accounting_treatment' => 'refundable_deposit'])->assertRedirect();
        $this->actingAs($manager)->post(route('invoices.post', $draft))->assertRedirect();
        $this->assertDatabaseCount('journal_lines', 2);

        $historical = Invoice::create(['organization_id' => $organization->id, 'reference' => 'INV-OLD-POSTED', 'status' => 'posted', 'due_on' => today(), 'subtotal' => 50, 'total' => 50]);
        JournalEntry::create(['organization_id' => $organization->id, 'reference' => 'JRN-OLD', 'event' => 'invoice.posted', 'subject_type' => $historical->getMorphClass(), 'subject_id' => $historical->id, 'posted_on' => today(), 'debit_total' => 50, 'credit_total' => 50]);
        $this->actingAs($manager)->post(route('invoices.pay', $historical), ['amount' => 50])->assertRedirect();
        $this->assertDatabaseCount('journal_lines', 2);
        $this->assertSame(0, JournalEntry::where('event', 'payment.received')->sole()->lines()->count());
    }
}
