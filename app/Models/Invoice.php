<?php

namespace App\Models;

use App\Domain\Finance\Models\CustomerCreditNote;
use App\Domain\Finance\Models\CustomerRefund;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'contact_id', 'reference', 'status', 'accounting_treatment', 'vat_treatment', 'vat_rate', 'vat_amount', 'issued_on', 'due_on', 'subtotal', 'total', 'currency'])]
class Invoice extends Model
{
    /** @return BelongsTo<CrmContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'contact_id');
    }

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'due_on' => 'date', 'subtotal' => 'decimal:2', 'total' => 'decimal:2', 'vat_rate' => 'decimal:2', 'vat_amount' => 'decimal:2'];
    }

    /** @return HasMany<CustomerCreditNote, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CustomerCreditNote::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<CustomerRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(CustomerRefund::class);
    }
}
