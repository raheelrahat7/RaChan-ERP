<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['organization_id', 'property_id', 'building_id', 'number', 'type', 'area', 'area_unit', 'status', 'asking_price', 'currency'])]
class Unit extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<Building, $this> */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /** @return MorphMany<Document, $this> */
    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
