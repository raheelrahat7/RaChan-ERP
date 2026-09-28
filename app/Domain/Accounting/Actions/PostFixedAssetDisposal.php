<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\FixedAsset;
use App\Domain\Accounting\Models\FixedAssetDepreciation;
use App\Domain\Accounting\Models\FixedAssetDisposal;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Accounting\Queries\DepreciationPreview;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PostFixedAssetDisposal
{
    public function __construct(private AccountingLedger $ledger, private DepreciationPreview $preview, private RecordOrganizationAuditLog $audit) {}

    public function handle(Organization $organization, User $actor, FixedAsset $asset, int $proceedsAccountId, string $proceeds): FixedAssetDisposal
    {
        return DB::transaction(function () use ($organization, $actor, $asset, $proceedsAccountId, $proceeds): FixedAssetDisposal {
            $locked = FixedAsset::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($asset->id);
            abort_unless($locked->disposed_on !== null, 422, 'Record the disposal date first.');
            abort_if(FixedAssetDisposal::where('fixed_asset_id', $locked->id)->whereNull('reversal_journal_entry_id')->exists(), 422, 'This asset already has an active disposal posting.');
            $month = $locked->disposed_on->format('Y-m');
            abort_if(collect($this->preview->for($organization, $month)['rows'])->contains('asset_id', $locked->id), 422, 'Post depreciation through the disposal date before approving disposal.');
            $allowedBankIds = BankAccount::where('organization_id', $organization->id)->where('currency', 'AED')->whereNotNull('ledger_account_id')->pluck('ledger_account_id');
            $proceedsAccount = LedgerAccount::where('organization_id', $organization->id)->where('is_active', true)->where('type', 'asset')->where(fn ($query) => $query->where('code', '1100')->orWhereIn('id', $allowedBankIds))->findOrFail($proceedsAccountId);
            $capital = $this->account($organization, $actor, '1500', 'Capital Assets', 'asset');
            $accumulated = FixedAssetDepreciation::where('fixed_asset_id', $locked->id)->whereNull('reversal_journal_entry_id')->where('month', '<=', $month)->sum('amount');
            $cost = (float) $locked->cost;
            $carrying = round($cost - (float) $accumulated, 2);
            $gainLoss = round((float) $proceeds - $carrying, 2);
            $lines = [];
            if ((float) $accumulated > 0) {
                $lines[] = ['ledger_account_id' => $this->account($organization, $actor, '1590', 'Accumulated Depreciation', 'asset')->id, 'debit' => $accumulated, 'credit' => 0];
            }
            if ((float) $proceeds > 0) {
                $lines[] = ['ledger_account_id' => $proceedsAccount->id, 'debit' => $proceeds, 'credit' => 0];
            }
            if ($gainLoss < 0) {
                $lines[] = ['ledger_account_id' => $this->account($organization, $actor, '5200', 'Loss on Asset Disposal', 'expense')->id, 'debit' => abs($gainLoss), 'credit' => 0];
            }
            $lines[] = ['ledger_account_id' => $capital->id, 'debit' => 0, 'credit' => $cost];
            if ($gainLoss > 0) {
                $lines[] = ['ledger_account_id' => $this->account($organization, $actor, '4100', 'Gain on Asset Disposal', 'income')->id, 'debit' => 0, 'credit' => $gainLoss];
            }
            $attempt = FixedAssetDisposal::where('fixed_asset_id', $locked->id)->count() + 1;
            $entry = $this->ledger->post($organization, $actor, $locked->disposed_on->toDateString(), 'Disposal '.$locked->reference, $lines, 'fixed_asset.disposed', "fixed-asset-disposal:{$locked->id}:{$attempt}");
            $disposal = FixedAssetDisposal::create(['organization_id' => $organization->id, 'fixed_asset_id' => $locked->id, 'proceeds_account_id' => $proceedsAccount->id, 'proceeds' => $proceeds, 'carrying_amount' => $carrying, 'gain_loss' => $gainLoss, 'journal_entry_id' => $entry->id, 'approved_by' => $actor->id]);
            $this->audit->handle($organization, $actor, 'accounting.fixed_asset.disposal_approved', $disposal, ['journal_entry_id' => $entry->id]);

            return $disposal;
        });
    }

    public function reverse(Organization $organization, User $actor, FixedAssetDisposal $disposal, string $date): void
    {
        DB::transaction(function () use ($organization, $actor, $disposal, $date): void {
            $locked = FixedAssetDisposal::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($disposal->id);
            abort_if($locked->reversal_journal_entry_id !== null, 422, 'This disposal is already reversed.');
            $reversal = $this->ledger->reverse($organization, $actor, $locked->journalEntry()->firstOrFail(), $date, false, false, true);
            $locked->update(['reversal_journal_entry_id' => $reversal->id, 'reversed_by' => $actor->id]);
            $this->audit->handle($organization, $actor, 'accounting.fixed_asset.disposal_reversed', $locked, ['reversal_journal_entry_id' => $reversal->id]);
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
