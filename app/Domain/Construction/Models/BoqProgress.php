<?php

namespace App\Domain\Construction\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'construction_boq_item_id', 'quantity', 'operation_key', 'note', 'recorded_by', 'voided_at', 'voided_by', 'void_reason'])]
class BoqProgress extends Model
{
    protected $table = 'construction_boq_progress';

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'voided_at' => 'datetime'];
    }
}
