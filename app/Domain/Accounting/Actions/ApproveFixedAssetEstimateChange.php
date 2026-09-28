<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\FixedAssetDepreciation;
use App\Domain\Accounting\Models\FixedAssetEstimateChange;
use App\Domain\Accounting\Models\FixedAssetImpairment;
use App\Domain\Accounting\Models\FixedAssetReview;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ApproveFixedAssetEstimateChange
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Organization $organization, User $actor, FixedAssetReview $review, array $input): FixedAssetEstimateChange
    {
        return DB::transaction(function () use ($organization, $actor, $review, $input): FixedAssetEstimateChange {
            $locked = FixedAssetReview::where('organization_id', $organization->id)->with('asset')->lockForUpdate()->findOrFail($review->id);
            abort_unless($locked->outcome === 'change_required', 422, 'The review must document that an estimate change is required.');
            abort_if(FixedAssetEstimateChange::where('fixed_asset_review_id', $locked->id)->exists(), 422, 'This review already has an approved estimate change.');
            $latestPosted = FixedAssetDepreciation::where('fixed_asset_id', $locked->fixed_asset_id)->whereNull('reversal_journal_entry_id')->max('month');
            $basisMonth = max($locked->reviewed_on->format('Y-m'), $latestPosted ?? '0000-00');
            $effectiveMonth = CarbonImmutable::createFromFormat('!Y-m', $basisMonth)->addMonth()->format('Y-m');
            $accumulated = FixedAssetDepreciation::where('fixed_asset_id', $locked->fixed_asset_id)->whereNull('reversal_journal_entry_id')->sum('amount');
            $impairment = FixedAssetImpairment::where('fixed_asset_id', $locked->fixed_asset_id)->whereNull('reversal_journal_entry_id')->sum('amount');
            $carryingAmount = round((float) $locked->asset->cost - (float) $accumulated - (float) $impairment, 2);
            abort_if((float) $input['residual_value'] > $carryingAmount, 422, 'Residual value cannot exceed the current carrying amount.');
            $change = FixedAssetEstimateChange::create(['organization_id' => $organization->id, 'fixed_asset_id' => $locked->fixed_asset_id, 'fixed_asset_review_id' => $locked->id, 'effective_month' => $effectiveMonth, 'residual_value' => $input['residual_value'], 'remaining_life_months' => $input['remaining_life_months'], 'approved_by' => $actor->id]);
            $this->audit->handle($organization, $actor, 'accounting.fixed_asset.estimate_change_approved', $change, ['effective_month' => $effectiveMonth, 'residual_value' => $input['residual_value'], 'remaining_life_months' => $input['remaining_life_months'], 'prospective' => true]);

            return $change;
        });
    }
}
