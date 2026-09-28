<?php

namespace App\Domain\Construction\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'construction_project_id', 'reference', 'description', 'unit', 'quantity', 'unit_rate_cents', 'amount_cents'])]
class BoqItem extends Model
{
    protected $table = 'construction_boq_items';

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_rate_cents' => 'integer', 'amount_cents' => 'integer'];
    }
}
