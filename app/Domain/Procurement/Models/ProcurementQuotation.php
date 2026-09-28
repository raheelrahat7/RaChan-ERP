<?php

namespace App\Domain\Procurement\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'rfq_id', 'vendor_reference', 'total', 'currency', 'status'])]
class ProcurementQuotation extends Model
{
    protected function casts(): array
    {
        return ['total' => 'decimal:2'];
    }
}
