<?php

namespace App\Domain\Accounting\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'name', 'starts_on', 'ends_on', 'status', 'closed_by', 'closed_at'])]
class AccountingPeriod extends Model
{
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'closed_at' => 'datetime'];
    }
}
