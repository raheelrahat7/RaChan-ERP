<?php

namespace App\Domain\Procurement\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['purchase_request_id', 'description', 'quantity', 'unit'])]
class PurchaseRequestLine extends Model {}
