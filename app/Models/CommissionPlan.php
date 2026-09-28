<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'name', 'basis', 'rate'])]
class CommissionPlan extends Model
{
    protected function casts(): array
    {
        return ['rate' => 'decimal:2'];
    }
}
