<?php

namespace App\Domain\Operations\Models;

use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'maintenance_request_id', 'created_by', 'label', 'is_required', 'completed_by', 'completed_at'])]
class JobTask extends Model
{
    protected $table = 'maintenance_job_tasks';

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<MaintenanceRequest, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
