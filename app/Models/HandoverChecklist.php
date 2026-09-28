<?php

namespace App\Models;

use App\Domain\Leasing\Models\HandoverInspectionItem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'lease_id', 'type', 'status', 'scheduled_on', 'completed_at', 'notes', 'created_by'])]
class HandoverChecklist extends Model
{
    /** @return HasMany<HandoverInspectionItem, $this> */
    public function inspectionItems(): HasMany
    {
        return $this->hasMany(HandoverInspectionItem::class)->orderBy('id');
    }

    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    protected function casts(): array
    {
        return ['scheduled_on' => 'date', 'completed_at' => 'datetime'];
    }
}
