<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'unit_id', 'contact_id', 'tenant_id', 'broker_id', 'reservation_id', 'reference', 'status', 'starts_on', 'ends_on', 'rent_amount', 'currency'])]
class Lease extends Model
{
    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return HasMany<HandoverChecklist, $this> */
    public function handovers(): HasMany
    {
        return $this->hasMany(HandoverChecklist::class);
    }

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'rent_amount' => 'decimal:2'];
    }
}
