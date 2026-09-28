<?php

namespace App\Domain\Finance\Models;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'invoice_id', 'reference', 'status', 'amount', 'vat_amount', 'vat_treatment', 'reason', 'posted_on', 'reversed_on', 'reversal_reason', 'journal_entry_id', 'reversal_journal_entry_id', 'created_by', 'posted_by', 'reversed_by'])]
class CustomerCreditNote extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'vat_amount' => 'decimal:2', 'posted_on' => 'date', 'reversed_on' => 'date'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return HasMany<CustomerRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(CustomerRefund::class);
    }
}
