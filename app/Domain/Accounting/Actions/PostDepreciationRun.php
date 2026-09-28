<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\FixedAsset;
use App\Domain\Accounting\Models\FixedAssetDepreciation;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Accounting\Queries\DepreciationPreview;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PostDepreciationRun
{
    public function __construct(private AccountingLedger $ledger, private DepreciationPreview $preview, private RecordOrganizationAuditLog $audit) {}

    public function handle(Organization $organization, User $actor, string $month): int
    {
        return DB::transaction(function () use ($organization, $actor, $month): int {
            $rows = $this->preview->for($organization, $month)['rows'];
            abort_if($rows === [], 422, 'No depreciation is eligible for this month.');
            $expense = $this->account($organization, $actor, '5100', 'Depreciation Expense', 'expense');
            $accumulated = $this->account($organization, $actor, '1590', 'Accumulated Depreciation', 'asset');
            $postedOn = CarbonImmutable::createFromFormat('!Y-m', $month)->endOfMonth()->toDateString();
            foreach ($rows as $row) {
                $asset = FixedAsset::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($row['asset_id']);
                abort_if(FixedAssetDepreciation::where('fixed_asset_id', $asset->id)->where('month', $month)->whereNull('reversal_journal_entry_id')->exists(), 422, 'Depreciation was already posted for an asset in this month.');
                $attempt = FixedAssetDepreciation::where('fixed_asset_id', $asset->id)->where('month', $month)->count() + 1;
                $entry = $this->ledger->post($organization, $actor, $postedOn, 'Depreciation '.$asset->reference.' '.$month, [['ledger_account_id' => $expense->id, 'debit' => $row['proposed_charge'], 'credit' => 0], ['ledger_account_id' => $accumulated->id, 'debit' => 0, 'credit' => $row['proposed_charge']]], 'depreciation.posted', "depreciation:{$asset->id}:{$month}:{$attempt}");
                $depreciation = FixedAssetDepreciation::create(['organization_id' => $organization->id, 'fixed_asset_id' => $asset->id, 'month' => $month, 'amount' => $row['proposed_charge'], 'journal_entry_id' => $entry->id, 'approved_by' => $actor->id]);
                $this->audit->handle($organization, $actor, 'accounting.depreciation.approved', $depreciation, ['month' => $month, 'amount' => $row['proposed_charge']]);
            }

            return count($rows);
        });
    }

    public function reverse(Organization $organization, User $actor, FixedAssetDepreciation $depreciation, string $date): void
    {
        DB::transaction(function () use ($organization, $actor, $depreciation, $date): void {
            $locked = FixedAssetDepreciation::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($depreciation->id);
            abort_if($locked->reversal_journal_entry_id !== null, 422, 'This depreciation is already reversed.');
            $reversal = $this->ledger->reverse($organization, $actor, $locked->journalEntry()->firstOrFail(), $date, false, true);
            $locked->update(['reversal_journal_entry_id' => $reversal->id, 'reversed_by' => $actor->id]);
            $this->audit->handle($organization, $actor, 'accounting.depreciation.reversed', $locked, ['reversal_journal_entry_id' => $reversal->id]);
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
