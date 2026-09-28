<?php

namespace App\Domain\Accounting\Models;

use App\Models\Property;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'property_id', 'vendor_bill_id', 'opening_source_reference', 'reference', 'name', 'asset_class', 'location', 'custodian', 'classification', 'cost', 'residual_value', 'useful_life_months', 'available_for_use_on', 'disposed_on', 'created_by'])]
class FixedAsset extends Model
{
    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<VendorBill, $this> */
    public function vendorBill(): BelongsTo
    {
        return $this->belongsTo(VendorBill::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<FixedAssetDepreciation, $this> */
    public function depreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class);
    }

    /** @return HasMany<FixedAssetReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(FixedAssetReview::class);
    }

    /** @return HasMany<FixedAssetEstimateChange, $this> */
    public function estimateChanges(): HasMany
    {
        return $this->hasMany(FixedAssetEstimateChange::class);
    }

    /** @return HasMany<FixedAssetDisposal, $this> */
    public function disposals(): HasMany
    {
        return $this->hasMany(FixedAssetDisposal::class);
    }

    /** @return HasMany<FixedAssetImpairment, $this> */
    public function impairments(): HasMany
    {
        return $this->hasMany(FixedAssetImpairment::class);
    }

    /** @return HasMany<FixedAssetTransfer, $this> */
    public function transfers(): HasMany
    {
        return $this->hasMany(FixedAssetTransfer::class);
    }

    protected function casts(): array
    {
        return ['cost' => 'decimal:2', 'residual_value' => 'decimal:2', 'useful_life_months' => 'integer', 'available_for_use_on' => 'date', 'disposed_on' => 'date'];
    }
}
