<?php

namespace App\Domain\Operations\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'stock_movement_id', 'amount_cents', 'currency', 'recorded_by', 'reason'])]
class StockReceiptCost extends Model
{
    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }
}
