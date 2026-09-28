<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'invoice_id', 'reference', 'amount', 'currency', 'received_on', 'method'])]
class Payment extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'received_on' => 'date'];
    }
}
