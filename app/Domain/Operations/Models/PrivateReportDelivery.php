<?php

namespace App\Domain\Operations\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'user_id', 'private_report_schedule_id', 'scheduled_for', 'generated_at', 'format', 'status', 'path', 'job_ids', 'failure_reason'])]
class PrivateReportDelivery extends Model
{
    protected function casts(): array
    {
        return ['job_ids' => 'array', 'scheduled_for' => 'immutable_datetime', 'generated_at' => 'immutable_datetime'];
    }
}
