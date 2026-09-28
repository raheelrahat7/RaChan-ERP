<?php

namespace App\Domain\Operations\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'amc_contract_id', 'maintenance_request_id', 'operations_equipment_id', 'service_on', 'override_reason', 'recorded_by'])]
class AmcServiceVisit extends Model
{
    protected function casts(): array
    {
        return ['service_on' => 'date:Y-m-d'];
    }
}
