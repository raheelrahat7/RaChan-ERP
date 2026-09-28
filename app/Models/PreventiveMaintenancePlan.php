<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'property_id', 'unit_id', 'vendor_id', 'title', 'frequency_days', 'next_due_on', 'is_active', 'auto_generate_enabled', 'requires_manager_confirmation', 'automation_last_run_at', 'automation_last_error'])]
class PreventiveMaintenancePlan extends Model
{
    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<MaintenanceVendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(MaintenanceVendor::class, 'vendor_id');
    }

    /** @return HasMany<MaintenanceRequest, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class, 'preventive_maintenance_plan_id');
    }

    protected function casts(): array
    {
        return ['auto_generate_enabled' => 'boolean', 'requires_manager_confirmation' => 'boolean', 'automation_last_run_at' => 'datetime', 'next_due_on' => 'date:Y-m-d', 'is_active' => 'boolean'];
    }
}
