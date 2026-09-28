<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\FixedAssetDepreciation;
use App\Domain\Accounting\Models\FixedAssetImpairment;
use App\Domain\Accounting\Models\FixedAssetReview;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PostFixedAssetImpairment
{
    public function __construct(private AccountingLedger $ledger, private RecordOrganizationAuditLog $audit) {}

    public function handle(Organization $organization, User $actor, FixedAssetReview $review, string $date, string $amount): FixedAssetImpairment
    {
        return DB::transaction(function () use ($organization, $actor, $review, $date, $amount): FixedAssetImpairment {
            $locked = FixedAssetReview::where('organization_id', $organization->id)->with('asset')->lockForUpdate()->findOrFail($review->id);
            abort_unless($locked->impairment_assessment_required, 422, 'The review must require an impairment assessment.');
            abort_if(FixedAssetImpairment::where('fixed_asset_review_id', $locked->id)->whereNull('reversal_journal_entry_id')->exists(), 422, 'Reverse the active impairment before posting a correction.');
            abort_if($date < $locked->reviewed_on->toDateString(), 422, 'Impairment cannot precede its review.');
            $postedMonth = substr($date, 0, 7);
            $latestDepreciation = FixedAssetDepreciation::where('fixed_asset_id', $locked->fixed_asset_id)->whereNull('reversal_journal_entry_id')->max('month');
            abort_if($latestDepreciation && $postedMonth < $latestDepreciation, 422, 'Post impairment in or after the latest depreciation month.');
            $depreciation = FixedAssetDepreciation::where('fixed_asset_id', $locked->fixed_asset_id)->whereNull('reversal_journal_entry_id')->sum('amount');
            $priorImpairment = FixedAssetImpairment::where('fixed_asset_id', $locked->fixed_asset_id)->whereNull('reversal_journal_entry_id')->sum('amount');
            $carrying = round((float) $locked->asset->cost - (float) $depreciation - (float) $priorImpairment, 2);
            abort_if((float) $amount > $carrying, 422, 'Impairment cannot exceed current carrying value.');
            $loss = $this->account($organization, $actor, '5300', 'Impairment Loss', 'expense');
            $accumulated = $this->account($organization, $actor, '1580', 'Accumulated Impairment', 'asset');
            $attempt = FixedAssetImpairment::where('fixed_asset_review_id', $locked->id)->count() + 1;
            $entry = $this->ledger->post($organization, $actor, $date, 'Impairment '.$locked->asset->reference, [['ledger_account_id' => $loss->id, 'debit' => $amount, 'credit' => 0], ['ledger_account_id' => $accumulated->id, 'debit' => 0, 'credit' => $amount]], 'fixed_asset.impaired', "fixed-asset-impairment:{$locked->id}:{$attempt}");
            $impairment = FixedAssetImpairment::create(['organization_id' => $organization->id, 'fixed_asset_id' => $locked->fixed_asset_id, 'fixed_asset_review_id' => $locked->id, 'posted_on' => $date, 'effective_month' => CarbonImmutable::createFromFormat('!Y-m', $postedMonth)->addMonth()->format('Y-m'), 'amount' => $amount, 'journal_entry_id' => $entry->id, 'approved_by' => $actor->id]);
            $this->audit->handle($organization, $actor, 'accounting.fixed_asset.impairment_approved', $impairment, ['amount' => $amount, 'effective_month' => $impairment->effective_month]);

            return $impairment;
        });
    }

    public function reverse(Organization $organization, User $actor, FixedAssetImpairment $impairment, string $date): void
    {
        DB::transaction(function () use ($organization, $actor, $impairment, $date): void {
            $locked = FixedAssetImpairment::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($impairment->id);
            abort_if($locked->reversal_journal_entry_id !== null, 422, 'This impairment is already reversed.');
            abort_if(FixedAssetDepreciation::where('fixed_asset_id', $locked->fixed_asset_id)->whereNull('reversal_journal_entry_id')->where('month', '>=', $locked->effective_month)->exists(), 422, 'Reverse later depreciation before reversing this impairment.');
            $reversal = $this->ledger->reverse($organization, $actor, $locked->journalEntry()->firstOrFail(), $date, false, false, false, true);
            $locked->update(['reversal_journal_entry_id' => $reversal->id, 'reversed_by' => $actor->id]);
            $this->audit->handle($organization, $actor, 'accounting.fixed_asset.impairment_reversed', $locked, ['reversal_journal_entry_id' => $reversal->id]);
        });
    }

    private function account(Organization $organization, User $actor, string $code, string $name, string $type): LedgerAccount
    {
        $account = LedgerAccount::firstOrCreate(['organization_id' => $organization->id, 'code' => $code], ['name' => $name, 'type' => $type, 'is_active' => true]);
        abort_unless($account->type === $type && $account->is_active, 422, "Account {$code} must be an active {$type} account.");
        if ($account->wasRecentlyCreated) {
            $this->audit->handle($organization, $actor, 'accounting.account.created', $account, ['automatic' => true]);
        }

        return $account;
    }
}
