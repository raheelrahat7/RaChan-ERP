<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Accounting\Actions\AccountingLedger;
use App\Domain\Finance\Models\CustomerCreditNote;
use App\Domain\Finance\Models\CustomerRefund;
use App\Domain\Finance\Services\InvoiceBalance;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageCustomerCreditNote
{
    public function __construct(private AccountingLedger $ledger, private InvoiceBalance $balances, private RecordOrganizationAuditLog $audit) {}

    public function create(Organization $org, User $actor, Invoice $invoice, string $amount, string $reason): CustomerCreditNote
    {
        Gate::forUser($actor)->authorize('manageFinance', $org);

        return DB::transaction(function () use ($org, $actor, $invoice, $amount, $reason): CustomerCreditNote {
            $locked = Invoice::where('organization_id', $org->id)->lockForUpdate()->findOrFail($invoice->id);
            $this->source($org, $locked);
            $this->assertAmount($locked, $amount);
            $this->check(trim($reason) !== '', 'reason', 'A credit reason is required.');
            $note = CustomerCreditNote::create(['organization_id' => $org->id, 'invoice_id' => $locked->id, 'reference' => 'CN-'.Str::upper(Str::random(12)), 'amount' => $amount, 'reason' => trim($reason), 'created_by' => $actor->id]);
            $this->audit->handle($org, $actor, 'finance.credit_note.created', $note, ['invoice_id' => $locked->id, 'amount' => $amount, 'reason' => $reason]);

            return $note;
        });
    }

    public function post(Organization $org, User $actor, CustomerCreditNote $note, string $date): void
    {
        Gate::forUser($actor)->authorize('manageFinance', $org);
        DB::transaction(function () use ($org, $actor, $note, $date): void {
            $invoice = Invoice::where('organization_id', $org->id)->lockForUpdate()->findOrFail($note->invoice_id);
            $locked = CustomerCreditNote::where('organization_id', $org->id)->lockForUpdate()->findOrFail($note->id);
            $this->check($locked->status === 'draft', 'credit_note', 'Only a draft credit note can be posted.');
            $source = $this->source($org, $invoice);
            $this->assertAmount($invoice, $locked->amount);
            $this->check($date >= $source->posted_on->toDateString() && $date <= today()->toDateString(), 'posted_on', 'Use a date on or after the invoice posting and no later than today.');
            // Chronological credit activity keeps cumulative VAT rounding and historical balances stable.
            $latest = $this->balances->latestCreditActivity($invoice);
            $this->check($latest === null || $date >= $latest, 'posted_on', 'Use a date on or after the latest credit-note activity.');
            $latestPayment = $invoice->payments()->max('received_on');
            $this->check($latestPayment === null || $date >= $latestPayment, 'posted_on', 'Use a date on or after the latest payment or rent-offset activity.');
            $cents = $this->balances->cents($locked->amount);
            $gross = $this->balances->cents($source->debit_total);
            $vatLine = $source->lines->first(fn ($line) => $line->account->code === '2200');
            $originalVat = $vatLine ? $this->balances->cents($vatLine->credit) : 0;
            $credited = $this->balances->creditedCents($invoice);
            $priorVat = $this->balances->cents($invoice->creditNotes()->whereNotNull('posted_on')->whereNull('reversed_on')->sum('vat_amount'));
            $vat = max(0, min($cents, $originalVat - $priorVat, (int) round(($credited + $cents) * $originalVat / $gross) - $priorVat));
            $lines = [];
            foreach ($source->lines as $line) {
                $value = match ($line->account->code) {
                    '1100' => $cents,
                    '2200' => $vat,
                    default => $cents - $vat,
                };
                if ($value > 0) {
                    $lines[] = ['ledger_account_id' => $line->ledger_account_id, 'debit' => $line->account->code === '1100' ? 0 : $value / 100, 'credit' => $line->account->code === '1100' ? $value / 100 : 0];
                }
            }
            $journal = $this->ledger->post($org, $actor, $date, $locked->reason, $lines, 'credit_note.posted', 'credit-note:'.$locked->id);
            $journal->update(['subject_type' => $locked->getMorphClass(), 'subject_id' => $locked->id]);
            $locked->update(['status' => 'posted', 'posted_on' => $date, 'posted_by' => $actor->id, 'vat_amount' => $vat / 100, 'vat_treatment' => $invoice->vat_treatment, 'journal_entry_id' => $journal->id]);
            $this->balances->refreshStatus($invoice);
            $this->audit->handle($org, $actor, 'finance.credit_note.posted', $locked, ['invoice_id' => $invoice->id, 'journal_entry_id' => $journal->id]);
        });
    }

    public function reverse(Organization $org, User $actor, CustomerCreditNote $note, string $date, string $reason): void
    {
        Gate::forUser($actor)->authorize('manageFinance', $org);
        DB::transaction(function () use ($org, $actor, $note, $date, $reason): void {
            $invoice = Invoice::where('organization_id', $org->id)->lockForUpdate()->findOrFail($note->invoice_id);
            $locked = CustomerCreditNote::where('organization_id', $org->id)->lockForUpdate()->findOrFail($note->id);
            $this->check($locked->status === 'posted', 'credit_note', 'Only a posted credit note can be reversed once.');
            $this->check(! CustomerRefund::where('organization_id', $org->id)->where('customer_credit_note_id', $locked->id)->whereNotNull('journal_entry_id')->whereNull('reversal_journal_entry_id')->exists(), 'credit_note', 'Reverse the approved cash refund before reversing this credit note.');
            $this->check($date >= $locked->posted_on?->toDateString() && $date <= today()->toDateString(), 'posted_on', 'Use a reversal date on or after posting and no later than today.');
            $this->check(trim($reason) !== '', 'reason', 'A reversal reason is required.');
            $latest = $this->balances->latestCreditActivity($invoice);
            $this->check($latest === null || $date >= $latest, 'posted_on', 'Use a date on or after the latest credit-note activity.');
            $journal = JournalEntry::where('organization_id', $org->id)->findOrFail($locked->journal_entry_id);
            $reversal = $this->ledger->reverse($org, $actor, $journal, $date, creditNote: true);
            $locked->update(['status' => 'reversed', 'reversed_on' => $date, 'reversed_by' => $actor->id, 'reversal_reason' => trim($reason), 'reversal_journal_entry_id' => $reversal->id]);
            $this->balances->refreshStatus($invoice);
            $this->audit->handle($org, $actor, 'finance.credit_note.reversed', $locked, ['invoice_id' => $invoice->id, 'journal_entry_id' => $reversal->id, 'reason' => $reason]);
        });
    }

    private function assertAmount(Invoice $invoice, string $amount): void
    {
        $cents = $this->balances->cents($amount);
        $this->check($cents > 0 && $cents <= $this->balances->creditableCents($invoice), 'amount', 'The credit must be positive and cannot exceed the unpaid invoice amount plus eligible cash already received.');
    }

    private function source(Organization $org, Invoice $invoice): JournalEntry
    {
        $this->check($invoice->currency === 'AED' && $invoice->accounting_treatment === 'revenue' && in_array($invoice->status, ['posted', 'partial', 'paid'], true), 'invoice', 'Choose a posted AED revenue invoice with an eligible unpaid amount or cash payment.');
        $entry = JournalEntry::where('organization_id', $org->id)->where('subject_type', $invoice->getMorphClass())->where('subject_id', $invoice->id)->where('event', 'invoice.posted')->with('lines.account')->lockForUpdate()->first();
        $this->check($entry !== null, 'invoice', 'The invoice requires its original detailed posting.');
        assert($entry !== null);
        $this->check(! JournalEntry::where('reversal_of_id', $entry->id)->exists(), 'invoice', 'The original invoice journal has been reversed.');
        $codes = $entry->lines->map(fn ($line) => $line->account->code)->sort()->values()->all();
        $this->check($codes === ['1100', '4000'] || $codes === ['1100', '2200', '4000'], 'invoice', 'The invoice requires detailed receivable, revenue and optional VAT lines.');
        $this->check($entry->currency === 'AED' && $this->balances->cents($entry->debit_total) === $this->balances->cents($invoice->total), 'invoice', 'The original journal must match the invoice total.');

        return $entry;
    }

    private function check(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
