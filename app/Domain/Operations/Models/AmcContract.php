<?php

namespace App\Domain\Operations\Models;

use App\Models\Property;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['organization_id', 'vendor_id', 'reference', 'title', 'starts_on', 'ends_on', 'service_limit', 'terms', 'cancelled_at', 'cancellation_reason', 'created_by'])]
class AmcContract extends Model
{
    protected function casts(): array
    {
        return ['service_limit' => 'integer', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'cancelled_at' => 'datetime'];
    }

    /** @return BelongsToMany<Property, $this> */
    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'amc_contract_properties');
    }

    /** @return BelongsToMany<OperationsEquipment, $this> */
    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(OperationsEquipment::class, 'amc_contract_equipment');
    }
}
