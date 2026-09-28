<?php

namespace App\Domain\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['organization_id', 'fixed_asset_id', 'review_year', 'reviewed_on', 'outcome', 'residual_value_snapshot', 'useful_life_months_snapshot', 'depreciation_method', 'impairment_assessment_required', 'notes', 'reviewed_by'])]
class FixedAssetReview extends Model
{
    /** @return BelongsTo<FixedAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return HasOne<FixedAssetEstimateChange, $this> */
    public function estimateChange(): HasOne
    {
        return $this->hasOne(FixedAssetEstimateChange::class);
    }

    /** @return HasMany<FixedAssetImpairment, $this> */
    public function impairments(): HasMany
    {
        return $this->hasMany(FixedAssetImpairment::class);
    }

    protected function casts(): array
    {
        return ['review_year' => 'integer', 'reviewed_on' => 'date', 'residual_value_snapshot' => 'decimal:2', 'useful_life_months_snapshot' => 'integer', 'impairment_assessment_required' => 'boolean'];
    }
}
