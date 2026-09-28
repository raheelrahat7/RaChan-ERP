<?php

namespace App\Domain\Procurement\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'purchase_request_id', 'vendor_id', 'reference', 'due_on', 'status'])]
class ProcurementRfq extends Model
{
    protected $table = 'procurement_rfqs';
}
