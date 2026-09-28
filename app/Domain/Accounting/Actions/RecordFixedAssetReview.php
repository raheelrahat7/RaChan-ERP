<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\FixedAsset;
use App\Domain\Accounting\Models\FixedAssetReview;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecordFixedAssetReview
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Organization $organization, User $actor, FixedAsset $asset, array $input): FixedAssetReview
    {
        return DB::transaction(function () use ($organization, $actor, $asset, $input): FixedAssetReview {
            $locked = FixedAsset::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($asset->id);
            abort_if(FixedAssetReview::where('fixed_asset_id', $locked->id)->where('review_year', $input['review_year'])->exists(), 422, 'This asset already has a review for the selected year.');

            $review = FixedAssetReview::create([
                'organization_id' => $organization->id,
                'fixed_asset_id' => $locked->id,
                'review_year' => $input['review_year'],
                'reviewed_on' => $input['reviewed_on'],
                'outcome' => $input['outcome'],
                'residual_value_snapshot' => $locked->residual_value,
                'useful_life_months_snapshot' => $locked->useful_life_months,
                'depreciation_method' => 'straight_line',
                'impairment_assessment_required' => $input['impairment_assessment_required'],
                'notes' => $input['notes'] ? trim($input['notes']) : null,
                'reviewed_by' => $actor->id,
            ]);
            $this->audit->handle($organization, $actor, 'accounting.fixed_asset.reviewed', $review, ['asset_id' => $locked->id, 'review_year' => $input['review_year'], 'outcome' => $input['outcome'], 'impairment_assessment_required' => $input['impairment_assessment_required']]);

            return $review;
        });
    }
}
