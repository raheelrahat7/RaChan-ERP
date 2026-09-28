<?php

namespace App\Domain\Construction\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'property_id', 'reference', 'title', 'budget_cents', 'status', 'created_by'])]
class ConstructionProject extends Model
{
    protected function casts(): array
    {
        return ['budget_cents' => 'integer'];
    }
}
