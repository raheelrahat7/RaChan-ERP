<?php

namespace App\Domain\Operations\Models;

use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'maintenance_request_id', 'recorded_by', 'category', 'description', 'quantity', 'unit_rate', 'amount', 'voided_by', 'voided_at', 'void_reason'])]
class JobCostLine extends Model
{
    protected $table = 'maintenance_job_cost_lines';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_rate' => 'decimal:2', 'amount' => 'decimal:2', 'voided_at' => 'datetime'];
    }

    /** @return BelongsTo<MaintenanceRequest, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
