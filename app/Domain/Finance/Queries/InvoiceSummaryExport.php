<?php

namespace App\Domain\Finance\Queries;

use App\Models\Invoice;
use App\Models\Organization;

class InvoiceSummaryExport
{
    /** @return array<int, list<mixed>> */
    public function rows(Organization $organization): array
    {
        return Invoice::where('organization_id', $organization->id)->orderBy('id')->get(['reference', 'status', 'total', 'currency', 'due_on'])
            ->map(fn (Invoice $item): array => [$item->reference, $item->status, $item->total, $item->currency, $item->due_on?->toDateString()])->values()->all();
    }
}
