<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Accounting\Actions\AccountingLedger;
use App\Domain\Accounting\Actions\PostMappedFinanceJournal;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Finance\Models\CustomerCreditNote;
use App\Domain\Finance\Models\CustomerRefund;
use App\Domain\Finance\Services\InvoiceBalance;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageCustomerRefund
{
    public function __construct(private InvoiceBalance $balances, private PostMappedFinanceJournal $journals, private AccountingLedger $ledger, private RecordOrganizationAuditLog $audit) {}

    public function request(Organization $organization, User $actor, Invoice $invoice, int $creditNoteId, string $amount, string $date, string $reason): CustomerRefund
    {
        Gate::forUser($actor)->authorize('manageFinance', $organization);

        return DB::transaction(function () use ($organization, $actor, $invoice, $creditNoteId, $amount, $date, $reason): CustomerRefund {
            $lockedInvoice = Invoice::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($invoice->id);
            $note = CustomerCreditNote::where('organization_id', $organization->id)->where('invoice_id', $lockedInvoice->id)->lockForUpdate()->findOrFail($creditNoteId);
            $this->assertEligible($organization, $lockedInvoice, $note, $amount, $date);
            $this->check(trim($reason) !== '', 'reason', 'A refund reason is required.');
            $refund = CustomerRefund::create([
                'organization_id' => $organization->id,
                'invoice_id' => $lockedInvoice->id,
                'customer_credit_note_id' => $note->id,
                'reference' => 'CRF-'.Str::upper(Str::random(12)),
                'amount' => $amount,
                'reason' => trim($reason),
                'status' => 'submitted',
                'requested_by' => $actor->id,
                'posted_on' => $date,
            ]);
            $this->audit->handle($organization, $actor, 'finance.customer_refund.submitted', $refund, ['invoice_id' => $lockedInvoice->id, 'credit_note_id' => $note->id, 'amount' => $amount, 'posted_on' => $date, 'reason' => trim($reason)]);

            return $refund;
        });
    }

    public function approve(Organization $organization, User $actor, CustomerRefund $refund): void
    {
        $this->authorizeOwner($organization, $actor);
        DB::transaction(function () use ($organization, $actor, $refund): void {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $locked = CustomerRefund::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($refund->id);
            $invoice = Invoice::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($locked->invoice_id);
            $note = CustomerCreditNote::where('organization_id', $organization->id)->where('invoice_id', $invoice->id)->lockForUpdate()->findOrFail($locked->customer_credit_note_id);
            $this->check($locked->status === 'submitted', 'refund', 'Only a submitted customer refund can be approved.');
            $this->check($locked->requested_by !== $actor->id, 'refund', 'The requester cannot approve their own refund.');
            $this->assertEligible($organization, $invoice, $note, $locked->amount, $locked->posted_on?->toDateString() ?? '');
            $journal = $this->journals->customerRefund($organization, $actor, $locked, $locked->amount, $locked->posted_on->toDateString());
            $locked->update(['status' => 'posted', 'approved_by' => $actor->id, 'approved_at' => now(), 'journal_entry_id' => $journal->id]);
            $this->audit->handle($organization, $actor, 'finance.customer_refund.approved', $locked, ['invoice_id' => $invoice->id, 'credit_note_id' => $note->id, 'journal_entry_id' => $journal->id, 'amount' => $locked->amount, 'posted_on' => $locked->posted_on->toDateString()]);
        });
    }

    public function reject(Organization $organization, User $actor, CustomerRefund $refund, string $reason): void
    {
        $this->authorizeOwner($organization, $actor);
        DB::transaction(function () use ($organization, $actor, $refund, $reason): void {
            $locked = CustomerRefund::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($refund->id);
            $this->check($locked->status === 'submitted', 'refund', 'Only a submitted customer refund can be rejected.');
            $this->check($locked->requested_by !== $actor->id, 'refund', 'The requester cannot reject their own refund.');
            $this->check(trim($reason) !== '', 'reason', 'A rejection reason is required.');
            $locked->update(['status' => 'rejected', 'rejected_by' => $actor->id, 'rejection_reason' => trim($reason)]);
            $this->audit->handle($organization, $actor, 'finance.customer_refund.rejected', $locked, ['reason' => trim($reason)]);
        });
    }

    public function reverse(Organization $organization, User $actor, CustomerRefund $refund, string $date, string $reason): void
    {
        $this->authorizeOwner($organization, $actor);
        DB::transaction(function () use ($organization, $actor, $refund, $date, $reason): void {
            $locked = CustomerRefund::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($refund->id);
            $this->check($locked->status === 'posted', 'refund', 'Only a posted customer refund can be reversed.');
            $this->check($date >= $locked->posted_on?->toDateString() && $date <= today()->toDateString(), 'posted_on', 'Use a reversal date on or after posting and no later than today.');
            $this->check(trim($reason) !== '', 'reason', 'A reversal reason is required.');
            $source = JournalEntry::where('organization_id', $organization->id)->findOrFail($locked->journal_entry_id);
            $lineIds = $source->lines()->pluck('id');
            $matched = BankStatementLine::whereIn('matched_journal_line_id', $lineIds)->exists();
            $this->check(! $matched, 'refund', 'Unmatch the refund from the bank statement before reversing it.');
            $reversal = $this->ledger->reverse($organization, $actor, $source, $date, customerRefund: true);
            $locked->update(['status' => 'reversed', 'reversed_by' => $actor->id, 'reversed_on' => $date, 'reversal_reason' => trim($reason), 'reversal_journal_entry_id' => $reversal->id]);
            $this->audit->handle($organization, $actor, 'finance.customer_refund.reversed', $locked, ['reversal_journal_entry_id' => $reversal->id, 'reason' => trim($reason)]);
        });
    }

    private function assertEligible(Organization $organization, Invoice $invoice, CustomerCreditNote $note, string $amount, string $date): void
    {
        $this->check($invoice->organization_id === $organization->id && $invoice->currency === 'AED' && $invoice->accounting_treatment === 'revenue', 'invoice', 'Refunds require an AED revenue invoice in this organization.');
        $this->check($note->organization_id === $organization->id && $note->invoice_id === $invoice->id && $note->status === 'posted', 'credit_note', 'Choose a posted credit note from this invoice.');
        $this->check($date !== '' && $date >= $note->posted_on?->toDateString() && $date <= today()->toDateString(), 'posted_on', 'Use a date on or after the credit note and no later than today.');
        $amountCents = $this->balances->cents($amount);
        $noteRefunded = CustomerRefund::where('organization_id', $organization->id)->where('customer_credit_note_id', $note->id)->whereNotNull('journal_entry_id')->whereNull('reversal_journal_entry_id')->sum('amount');
        $noteAvailable = max(0, $this->balances->cents($note->amount) - $this->balances->cents($noteRefunded));
        $cashAvailable = $this->balances->refundableCashCents($invoice, $date);
        $this->check($amountCents > 0 && $amountCents <= min($noteAvailable, $cashAvailable), 'amount', 'Refunds are limited to the unrefunded credit-note amount and actual cash received against this invoice.');
    }

    private function authorizeOwner(Organization $organization, User $actor): void
    {
        Gate::forUser($actor)->authorize('viewFinance', $organization);
        $this->check($actor->hasOrganizationRole($organization, OrganizationRole::Owner), 'refund', 'Only the organization owner can approve, reject, or reverse customer refunds.');
    }

    private function check(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
