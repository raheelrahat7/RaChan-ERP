<?php

namespace App\Domain\Fleet\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'fleet_vehicle_id', 'user_id', 'recorded_by', 'reason', 'started_at', 'ended_at', 'return_reason', 'returned_by'])]
class FleetAssignment extends Model
{
    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
