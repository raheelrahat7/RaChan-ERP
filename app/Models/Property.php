<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['organization_id', 'name', 'type', 'address_line_1', 'city', 'country', 'description'])]
class Property extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Building, $this> */
    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    /** @return HasMany<Unit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** @return BelongsToMany<Owner, $this> */
    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(Owner::class, 'property_owner')->withPivot('ownership_share')->withTimestamps();
    }
}
