<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property-read Pivot $pivot
 * @property int $organization_id
 * @property string $name
 * @property string|null $reference
 */
#[Fillable(['organization_id', 'name', 'email', 'phone', 'reference', 'payment_terms', 'commission_notes'])]
class Owner extends Model
{
    /** @return BelongsToMany<Property, $this> */
    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'property_owner')->withPivot('ownership_share')->withTimestamps();
    }
}
