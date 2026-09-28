<?php

namespace App\Models;

use App\Domain\Finance\Models\VendorCreditNote;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'vendor_id', 'property_id', 'reference', 'description', 'status', 'accounting_treatment', 'vat_treatment', 'vat_rate', 'vat_amount', 'input_vat_recoverable', 'bill_date', 'due_on', 'total', 'currency'])]
class VendorBill extends Model
{
    /** @return HasMany<VendorBillPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(VendorBillPayment::class);
    }

    /** @return HasMany<VendorCreditNote, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(VendorCreditNote::class);
    }

    /** @return BelongsTo<MaintenanceVendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(MaintenanceVendor::class, 'vendor_id');
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    protected function casts(): array
    {
        return ['bill_date' => 'date', 'due_on' => 'date', 'total' => 'decimal:2', 'vat_rate' => 'decimal:2', 'vat_amount' => 'decimal:2', 'input_vat_recoverable' => 'boolean'];
    }
}
