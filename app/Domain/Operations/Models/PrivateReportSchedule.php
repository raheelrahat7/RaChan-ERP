<?php

namespace App\Domain\Operations\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'user_id', 'name', 'filters', 'format', 'frequency', 'local_time', 'weekday', 'enabled', 'next_run_at'])]
class PrivateReportSchedule extends Model
{
    protected function casts(): array
    {
        return ['filters' => 'array', 'enabled' => 'boolean', 'weekday' => 'integer', 'next_run_at' => 'immutable_datetime'];
    }
}
