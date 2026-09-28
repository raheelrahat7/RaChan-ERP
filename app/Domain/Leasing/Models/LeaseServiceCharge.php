<?php

namespace App\Domain\Leasing\Models;

use App\Models\Invoice;
use App\Models\Lease;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'lease_id', 'invoice_id', 'category', 'period_starts_on', 'period_ends_on', 'net_amount', 'vat_treatment', 'due_on', 'notes', 'created_by'])]
class LeaseServiceCharge extends Model
{
    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected function casts(): array
    {
        return ['period_starts_on' => 'date', 'period_ends_on' => 'date', 'net_amount' => 'decimal:2', 'due_on' => 'date'];
    }
}
