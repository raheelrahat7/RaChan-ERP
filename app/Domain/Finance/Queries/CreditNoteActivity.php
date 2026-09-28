<?php

namespace App\Domain\Finance\Queries;

use App\Domain\Finance\Models\CustomerCreditNote;
use App\Models\Organization;

class CreditNoteActivity
{
    /** @return list<array{invoice_id: int, date: string, reference: string, treatment: string|null, net: numeric-string, vat: numeric-string, gross: numeric-string}> */
    public function for(Organization $organization, string $from, string $to): array
    {
        $notes = CustomerCreditNote::where('organization_id', $organization->id)->whereNotNull('posted_on')
            ->where(fn ($query) => $query->whereBetween('posted_on', [$from, $to])->orWhereBetween('reversed_on', [$from, $to]))->get();
        $rows = [];
        foreach ($notes as $note) {
            foreach ([[$note->posted_on, -1, $note->reference], [$note->reversed_on, 1, $note->reference.' reversal']] as [$date, $sign, $reference]) {
                if ($date && $date->toDateString() >= $from && $date->toDateString() <= $to) {
                    $rows[] = ['invoice_id' => $note->invoice_id, 'date' => $date->toDateString(), 'reference' => $reference, 'treatment' => $note->vat_treatment, 'net' => $this->money($sign * ((float) $note->amount - (float) $note->vat_amount)), 'vat' => $this->money($sign * (float) $note->vat_amount), 'gross' => $this->money($sign * (float) $note->amount)];
                }
            }
        }

        return $rows;
    }

    /** @return numeric-string */
    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
