<?php

namespace App\Domain\Fleet\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'fleet_vehicle_id', 'vendor_id', 'maintenance_request_id', 'operation_key', 'kind', 'description', 'due_on', 'status', 'completed_odometer', 'completion_note', 'completed_at', 'completed_by', 'cancellation_reason'])]
class FleetService extends Model
{
    protected function casts(): array
    {
        return ['due_on' => 'date:Y-m-d', 'completed_odometer' => 'integer', 'completed_at' => 'datetime'];
    }
}
