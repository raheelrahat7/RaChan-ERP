<?php

namespace App\Domain\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'fixed_asset_id', 'fixed_asset_review_id', 'effective_month', 'residual_value', 'remaining_life_months', 'approved_by'])]
class FixedAssetEstimateChange extends Model
{
    /** @return BelongsTo<FixedAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    /** @return BelongsTo<FixedAssetReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(FixedAssetReview::class, 'fixed_asset_review_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected function casts(): array
    {
        return ['residual_value' => 'decimal:2', 'remaining_life_months' => 'integer'];
    }
}
