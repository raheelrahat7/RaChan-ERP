<?php

namespace App\Models;

use App\Domain\Operations\Models\JobCostLine;
use App\Domain\Operations\Models\JobNote;
use App\Domain\Operations\Models\JobSlaCycle;
use App\Domain\Operations\Models\JobTask;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['organization_id', 'property_id', 'unit_id', 'assigned_to', 'vendor_id', 'reference', 'title', 'description', 'priority', 'status', 'due_at', 'estimated_cost', 'actual_cost', 'currency', 'completed_at', 'preventive_maintenance_plan_id', 'preventive_due_on', 'requires_manager_confirmation', 'submitted_at', 'submitted_by', 'confirmed_at', 'confirmed_by', 'completed_by'])]
class MaintenanceRequest extends Model
{
    /** @return HasMany<JobSlaCycle, $this> */
    public function slaCycles(): HasMany
    {
        return $this->hasMany(JobSlaCycle::class);
    }

    /** @return HasMany<JobNote, $this> */
    public function jobNotes(): HasMany
    {
        return $this->hasMany(JobNote::class);
    }

    /** @return HasMany<JobTask, $this> */
    public function jobTasks(): HasMany
    {
        return $this->hasMany(JobTask::class);
    }

    /** @return HasMany<JobCostLine, $this> */
    public function jobCostLines(): HasMany
    {
        return $this->hasMany(JobCostLine::class);
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    protected function casts(): array
    {
        return ['requires_manager_confirmation' => 'boolean', 'submitted_at' => 'datetime', 'confirmed_at' => 'datetime', 'due_at' => 'datetime', 'completed_at' => 'datetime', 'preventive_due_on' => 'date:Y-m-d', 'estimated_cost' => 'decimal:2', 'actual_cost' => 'decimal:2'];
    }
}
