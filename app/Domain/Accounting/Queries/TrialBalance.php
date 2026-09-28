<?php

namespace App\Domain\Accounting\Queries;

use App\Domain\Accounting\Models\LedgerAccount;
use App\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TrialBalance
{
    /** @return Collection<int, array{id: int, code: string, name: string, type: string, is_active: bool, debit: string, credit: string}> */
    public function forOrganization(Organization $organization, ?string $asOf = null): Collection
    {
        $balances = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.organization_id', $organization->id)->where('journal_entries.currency', 'AED')
            ->when($asOf, fn ($query) => $query->where('journal_entries.posted_on', '<=', $asOf))
            ->selectRaw('journal_lines.ledger_account_id, SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->groupBy('journal_lines.ledger_account_id')->get()->keyBy('ledger_account_id');

        return LedgerAccount::where('organization_id', $organization->id)->orderBy('code')->get()
            ->map(fn (LedgerAccount $account) => [
                ...$account->only('id', 'code', 'name', 'type', 'is_active'),
                'debit' => (string) ($balances[$account->id]->debit ?? '0.00'),
                'credit' => (string) ($balances[$account->id]->credit ?? '0.00'),
            ]);
    }
}
