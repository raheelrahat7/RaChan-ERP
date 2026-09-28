<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'vendor_bill_id', 'reference', 'amount', 'paid_on'])]
class VendorBillPayment extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date'];
    }
}
