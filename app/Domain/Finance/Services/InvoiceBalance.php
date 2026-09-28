<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\CustomerCreditNote;
use App\Domain\Finance\Models\CustomerRefund;
use App\Models\Invoice;
use App\Models\Payment;

class InvoiceBalance
{
    public function hasActiveCredits(int $organizationId, int $invoiceId): bool
    {
        return CustomerCreditNote::where('organization_id', $organizationId)->where('invoice_id', $invoiceId)->where('status', 'posted')->exists();
    }

    public function creditedCents(Invoice $invoice, ?string $asOf = null): int
    {
        $query = $invoice->creditNotes()->whereNotNull('posted_on');
        if ($asOf === null) {
            $query->whereNull('reversed_on');
        } else {
            $query->whereDate('posted_on', '<=', $asOf)->where(fn ($q) => $q->whereNull('reversed_on')->orWhereDate('reversed_on', '>', $asOf));
        }

        return $this->cents($query->sum('amount'));
    }

    public function latestCreditActivity(Invoice $invoice): ?string
    {
        return $invoice->creditNotes()->whereNotNull('posted_on')->get()
            ->flatMap(fn (CustomerCreditNote $note) => [$note->posted_on?->toDateString(), $note->reversed_on?->toDateString()])->filter()->max();
    }

    public function outstandingCents(Invoice $invoice): int
    {
        return max(0, $this->cents($invoice->total) - $this->cents($invoice->payments_sum_amount ?? $invoice->payments()->sum('amount')) - $this->creditedCents($invoice));
    }

    public function directCashReceivedCents(Invoice $invoice, ?string $asOf = null): int
    {
        $payments = Payment::where('organization_id', $invoice->organization_id)
            ->where('invoice_id', $invoice->id)
            ->where('currency', 'AED')
            ->where(fn ($query) => $query->whereNull('method')->orWhereNotIn('method', ['security_deposit_offset', 'security_deposit_offset_reversal']))
            ->when($asOf, fn ($query) => $query->whereDate('received_on', '<=', $asOf))
            ->sum('amount');

        return $this->cents($payments);
    }

    public function creditableCents(Invoice $invoice): int
    {
        $gross = $this->cents($invoice->total);
        $remainingCredit = max(0, $gross - $this->creditedCents($invoice));

        return min($remainingCredit, $this->outstandingCents($invoice) + $this->directCashReceivedCents($invoice));
    }

    public function refundableCashCents(Invoice $invoice, ?string $asOf = null): int
    {
        $gross = $this->cents($invoice->total);
        $cash = $this->directCashReceivedCents($invoice, $asOf);
        $credits = $this->creditedCents($invoice, $asOf);
        $refundQuery = CustomerRefund::where('organization_id', $invoice->organization_id)
            ->where('invoice_id', $invoice->id)
            ->whereNotNull('journal_entry_id')
            ->when($asOf, fn ($query) => $query->whereDate('posted_on', '<=', $asOf)->where(fn ($query) => $query->whereNull('reversed_on')->orWhereDate('reversed_on', '>', $asOf)), fn ($query) => $query->whereNull('reversal_journal_entry_id'));
        $refunds = $refundQuery->sum('amount');

        return max(0, $cash + $credits - $gross - $this->cents($refunds));
    }

    public function refreshStatus(Invoice $invoice): void
    {
        $paid = $this->cents($invoice->payments()->sum('amount'));
        $allocated = $paid + $this->creditedCents($invoice);
        $invoice->update(['status' => $allocated >= $this->cents($invoice->total) ? 'paid' : ($allocated > 0 ? 'partial' : 'posted')]);
    }

    public function cents(string|int|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
