<?php

namespace App\Domain\Operations\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'property_id', 'reference', 'name', 'serial_number'])]
class OperationsEquipment extends Model
{
    protected $table = 'operations_equipment';
}
