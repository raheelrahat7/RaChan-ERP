<?php

namespace App\Domain\Leasing\Models;

use App\Models\HandoverChecklist;
use App\Models\Lease;
use App\Models\Organization;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'unit_id', 'previous_lease_id', 'handover_checklist_id', 'vacant_from', 'target_ready_on', 'readiness_status', 'notes', 'resolved_at', 'resolution', 'updated_by'])]
class VacancyRecord extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Lease, $this> */
    public function previousLease(): BelongsTo
    {
        return $this->belongsTo(Lease::class, 'previous_lease_id');
    }

    /** @return BelongsTo<HandoverChecklist, $this> */
    public function handover(): BelongsTo
    {
        return $this->belongsTo(HandoverChecklist::class, 'handover_checklist_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function casts(): array
    {
        return ['vacant_from' => 'date', 'target_ready_on' => 'date', 'resolved_at' => 'datetime'];
    }
}
