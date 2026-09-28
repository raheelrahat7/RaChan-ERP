<?php

namespace App\Domain\Procurement\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'purchase_request_id', 'quotation_id', 'vendor_id', 'vendor_bill_id', 'reference', 'total', 'currency', 'status', 'received_on', 'receipt_note'])]
class PurchaseOrder extends Model
{
    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'received_on' => 'date'];
    }
}
