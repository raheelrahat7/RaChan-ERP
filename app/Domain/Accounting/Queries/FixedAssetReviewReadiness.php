<?php

namespace App\Domain\Accounting\Queries;

use App\Domain\Accounting\Models\FixedAsset;
use App\Domain\Accounting\Models\FixedAssetReview;
use App\Models\Organization;

class FixedAssetReviewReadiness
{
    /**
     * @return array<string, mixed>
     */
    public function for(Organization $organization, int $year): array
    {
        $yearStart = "{$year}-01-01";
        $yearEnd = "{$year}-12-31";
        $eligibleAssets = FixedAsset::where('organization_id', $organization->id)
            ->where('available_for_use_on', '<=', $yearEnd)
            ->where(fn ($query) => $query->whereNull('disposed_on')->orWhere('disposed_on', '>=', $yearStart));
        $yearReviews = FixedAssetReview::where('organization_id', $organization->id)->where('review_year', $year);
        $reviewedAssetIds = (clone $yearReviews)->pluck('fixed_asset_id');

        return [
            'year' => $year,
            'eligible_count' => (clone $eligibleAssets)->count(),
            'missing' => (clone $eligibleAssets)->whereNotIn('id', $reviewedAssetIds)->orderBy('reference')->get(['id', 'reference', 'name']),
            'attention' => (clone $yearReviews)->where(fn ($query) => $query->where('outcome', 'change_required')->orWhere('impairment_assessment_required', true))->with('asset:id,reference,name')->latest('reviewed_on')->get(),
        ];
    }
}
