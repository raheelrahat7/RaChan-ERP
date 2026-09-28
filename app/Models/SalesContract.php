<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'unit_id', 'contact_id', 'broker_id', 'reservation_id', 'reference', 'status', 'contracted_on', 'sale_price', 'currency'])]
class SalesContract extends Model
{
    protected function casts(): array
    {
        return ['contracted_on' => 'date', 'sale_price' => 'decimal:2'];
    }
}
