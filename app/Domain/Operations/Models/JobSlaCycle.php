<?php

namespace App\Domain\Operations\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'maintenance_request_id', 'cycle_number', 'timezone', 'working_days', 'holidays', 'response_seconds', 'resolution_seconds', 'started_at', 'acknowledged_at', 'acknowledged_by', 'holds', 'held_at', 'closed_at', 'outcome'])]
class JobSlaCycle extends Model
{
    protected function casts(): array
    {
        return ['cycle_number' => 'integer', 'working_days' => 'array', 'holidays' => 'array', 'holds' => 'array', 'response_seconds' => 'integer', 'resolution_seconds' => 'integer', 'started_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime', 'held_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }
}
