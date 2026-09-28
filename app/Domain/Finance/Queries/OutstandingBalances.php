<?php

namespace App\Domain\Finance\Queries;

use App\Domain\Finance\Services\InvoiceBalance;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\VendorBill;
use Carbon\CarbonImmutable;

class OutstandingBalances
{
    /**
     * @return array<string, mixed>
     */
    public function for(Organization $organization, string $asOf, string $scope = 'all'): array
    {
        $date = CarbonImmutable::parse($asOf)->startOfDay();

        $receivables = Invoice::query()
            ->where('organization_id', $organization->id)
            ->where('currency', 'AED')
            ->whereNot('status', 'draft')
            ->whereDate('issued_on', '<=', $asOf)
            ->with('contact:id,first_name,last_name')
            ->withSum(['payments as paid_as_of' => fn ($query) => $query->whereDate('received_on', '<=', $asOf)], 'amount')
            ->orderByRaw('due_on is null, due_on')
            ->orderBy('reference')
            ->get()
            ->map(fn (Invoice $invoice) => $this->row($invoice->id, $invoice->reference, $invoice->contact ? trim($invoice->contact->first_name.' '.$invoice->contact->last_name) : null, $invoice->issued_on?->toDateString(), $invoice->due_on?->toDateString(), (string) $invoice->total, (string) ($invoice->paid_as_of ?? '0'), $invoice->currency, $date, app(InvoiceBalance::class)->creditedCents($invoice, $asOf)))
            ->filter(fn (array $row) => $row['balance_cents'] > 0)
            ->filter(fn (array $row) => $this->inScope($row, $scope))
            ->values();

        $payables = VendorBill::query()
            ->where('organization_id', $organization->id)
            ->where('currency', 'AED')
            ->whereNot('status', 'draft')
            ->whereDate('bill_date', '<=', $asOf)
            ->with('vendor:id,name')
            ->withSum(['payments as paid_as_of' => fn ($query) => $query->whereDate('paid_on', '<=', $asOf)], 'amount')
            ->withSum(['creditNotes as credited_as_of' => fn ($query) => $query->where('status', 'posted')->whereNull('reversed_on')->whereDate('posted_on', '<=', $asOf)], 'amount')
            ->orderByRaw('due_on is null, due_on')
            ->orderBy('reference')
            ->get()
            ->map(fn (VendorBill $bill) => $this->row($bill->id, $bill->reference, $bill->vendor?->name, $bill->bill_date->toDateString(), $bill->due_on?->toDateString(), (string) $bill->total, (string) ($bill->paid_as_of ?? '0'), $bill->currency, $date, $this->cents((string) ($bill->credited_as_of ?? '0'))))
            ->filter(fn (array $row) => $row['balance_cents'] > 0)
            ->filter(fn (array $row) => $this->inScope($row, $scope))
            ->values();

        return [
            'receivables' => $receivables->map(fn (array $row) => $this->publicRow($row))->all(),
            'payables' => $payables->map(fn (array $row) => $this->publicRow($row))->all(),
            'total_receivables' => $this->money($receivables->sum('balance_cents')),
            'total_payables' => $this->money($payables->sum('balance_cents')),
            'overdue_receivables_count' => $receivables->where('days_overdue', '>', 0)->count(),
            'overdue_payables_count' => $payables->where('days_overdue', '>', 0)->count(),
            'overdue_receivables' => $this->money($receivables->where('days_overdue', '>', 0)->sum('balance_cents')),
            'overdue_payables' => $this->money($payables->where('days_overdue', '>', 0)->sum('balance_cents')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, string $reference, ?string $party, ?string $documentDate, ?string $dueOn, string $total, string $paid, string $currency, CarbonImmutable $asOf, int $creditedCents = 0): array
    {
        $totalCents = $this->cents($total);
        $paidCents = $this->cents($paid);
        $balanceCents = max(0, $totalCents - $paidCents - $creditedCents);
        $dueDate = $dueOn ? CarbonImmutable::parse($dueOn)->startOfDay() : null;

        return [
            'id' => $id, 'reference' => $reference, 'party' => $party,
            'document_date' => $documentDate, 'due_on' => $dueOn,
            'total' => $this->money($totalCents), 'paid' => $this->money($paidCents),
            'credited' => $this->money($creditedCents), 'balance' => $this->money($balanceCents), 'balance_cents' => $balanceCents,
            'currency' => $currency,
            'days_overdue' => $dueDate?->lt($asOf) ? $dueDate->diffInDays($asOf) : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function publicRow(array $row): array
    {
        unset($row['balance_cents']);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function inScope(array $row, string $scope): bool
    {
        return match ($scope) {
            'overdue' => $row['days_overdue'] > 0,
            'not_overdue' => $row['days_overdue'] === 0,
            default => true,
        };
    }

    private function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
