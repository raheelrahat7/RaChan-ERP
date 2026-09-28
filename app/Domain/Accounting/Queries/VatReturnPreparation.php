<?php

namespace App\Domain\Accounting\Queries;

use App\Domain\Finance\Models\VendorCreditNote;
use App\Domain\Finance\Queries\CreditNoteActivity;
use App\Domain\Leasing\Models\LeaseDepositDeduction;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\VendorBill;

class VatReturnPreparation
{
    /**
     * @return array<string, mixed>
     */
    public function for(Organization $organization, string $from, string $to): array
    {
        $sales = Invoice::where('organization_id', $organization->id)->whereIn('status', ['posted', 'partial', 'paid'])->whereBetween('issued_on', [$from, $to])->whereNotNull('vat_treatment')->orderBy('issued_on')->get();
        $purchases = VendorBill::where('organization_id', $organization->id)->whereIn('status', ['posted', 'partial', 'paid'])->whereBetween('bill_date', [$from, $to])->whereNotNull('vat_treatment')->orderBy('bill_date')->get();
        $salesRows = $sales->map(fn (Invoice $invoice) => ['date' => $invoice->issued_on?->toDateString(), 'reference' => $invoice->reference, 'treatment' => $invoice->vat_treatment, 'net' => $invoice->subtotal, 'vat' => $invoice->vat_amount ?? '0.00', 'gross' => $invoice->total]);
        $deductions = LeaseDepositDeduction::where('organization_id', $organization->id)->whereNotNull('vat_treatment')->get();
        $sources = [];
        foreach ($deductions as $deduction) {
            foreach ([$deduction->recovery_journal_entry_id, $deduction->forfeiture_journal_entry_id] as $journalId) {
                if ($journalId) {
                    $sources[$journalId] = $deduction;
                }
            }
        }
        $journalRows = JournalEntry::where('organization_id', $organization->id)->whereBetween('posted_on', [$from, $to])->where(fn ($query) => $query->whereIn('id', array_keys($sources))->orWhereIn('reversal_of_id', array_keys($sources)))->get()->map(function (JournalEntry $entry) use ($sources) {
            $deduction = $sources[$entry->reversal_of_id ?? $entry->id];
            $sign = $entry->reversal_of_id ? -1 : 1;

            return ['date' => $entry->posted_on->toDateString(), 'reference' => $entry->reference, 'treatment' => $deduction->vat_treatment, 'net' => $this->money($sign * ((float) $deduction->amount - (float) $deduction->vat_amount)), 'vat' => $this->money($sign * (float) $deduction->vat_amount), 'gross' => $this->money($sign * (float) $deduction->amount)];
        });
        foreach (app(CreditNoteActivity::class)->for($organization, $from, $to) as $activity) {
            if ($activity['treatment'] !== null) {
                unset($activity['invoice_id']);
                $salesRows->push($activity);
            }
        }
        $salesRows = $salesRows->concat($journalRows)->sortBy('date')->values();
        $purchaseRows = $purchases->map(fn (VendorBill $bill) => ['date' => $bill->bill_date->toDateString(), 'reference' => $bill->reference, 'treatment' => $bill->vat_treatment, 'gross' => $bill->total, 'vat' => $bill->vat_amount ?? '0.00', 'recoverable_vat' => $bill->input_vat_recoverable ? ($bill->vat_amount ?? '0.00') : '0.00']);
        $supplierCredits = VendorCreditNote::where('organization_id', $organization->id)->whereIn('status', ['posted', 'reversed'])->where(function ($query) use ($from, $to): void {
            $query->whereBetween('posted_on', [$from, $to])->orWhereBetween('reversed_on', [$from, $to]);
        })->get();
        foreach ($supplierCredits as $credit) {
            if ($credit->posted_on && $credit->posted_on->toDateString() >= $from && $credit->posted_on->toDateString() <= $to && $credit->vat_treatment !== null) {
                $purchaseRows->push(['date' => $credit->posted_on->toDateString(), 'reference' => $credit->reference, 'treatment' => $credit->vat_treatment, 'gross' => $this->money(-((float) $credit->amount)), 'vat' => $this->money(-((float) $credit->vat_amount)), 'recoverable_vat' => $credit->input_vat_recoverable ? $this->money(-((float) $credit->vat_amount)) : '0.00']);
            }
            if ($credit->reversed_on && $credit->reversed_on->toDateString() >= $from && $credit->reversed_on->toDateString() <= $to && $credit->vat_treatment !== null) {
                $purchaseRows->push(['date' => $credit->reversed_on->toDateString(), 'reference' => 'Reversal '.$credit->reference, 'treatment' => $credit->vat_treatment, 'gross' => $credit->amount, 'vat' => $credit->vat_amount, 'recoverable_vat' => $credit->input_vat_recoverable ? $credit->vat_amount : '0.00']);
            }
        }
        $purchaseRows = $purchaseRows->sortBy('date')->values();
        $output = round($salesRows->sum(fn (array $item) => (float) $item['vat']), 2);
        $input = round($purchaseRows->sum(fn (array $item) => (float) $item['recoverable_vat']), 2);

        return ['from' => $from, 'to' => $to, 'sales' => $salesRows, 'purchases' => $purchaseRows, 'totals' => [
            'standard_sales_net' => $this->money($salesRows->where('treatment', 'standard')->sum(fn (array $item) => (float) $item['net'])),
            'zero_rated_sales' => $this->money($salesRows->where('treatment', 'zero_rated')->sum(fn (array $item) => (float) $item['net'])),
            'exempt_sales' => $this->money($salesRows->where('treatment', 'exempt')->sum(fn (array $item) => (float) $item['net'])),
            'out_of_scope_sales' => $this->money($salesRows->where('treatment', 'out_of_scope')->sum(fn (array $item) => (float) $item['net'])),
            'output_vat' => $this->money($output), 'recoverable_input_vat' => $this->money($input), 'net_vat' => $this->money($output - $input),
        ]];
    }

    /** @return numeric-string */
    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
