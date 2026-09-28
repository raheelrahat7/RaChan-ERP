<?php

namespace App\Domain\Accounting\Models;

use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'fixed_asset_id', 'transferred_on', 'from_property_id', 'to_property_id', 'from_location', 'to_location', 'from_custodian', 'to_custodian', 'from_asset_class', 'to_asset_class', 'reason', 'approved_by'])]
class FixedAssetTransfer extends Model
{
    /** @return BelongsTo<FixedAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    /** @return BelongsTo<Property, $this> */
    public function fromProperty(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'from_property_id');
    }

    /** @return BelongsTo<Property, $this> */
    public function toProperty(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'to_property_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected function casts(): array
    {
        return ['transferred_on' => 'date'];
    }
}
